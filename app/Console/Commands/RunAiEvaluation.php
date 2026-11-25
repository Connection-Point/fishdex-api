<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Identification\Data\IdentificationInput;
use App\Domain\Identification\Exceptions\ProviderRateLimited;
use App\Domain\Identification\Services\AiCapacity;
use App\Domain\Identification\Services\CandidateMatcher;
use App\Domain\Identification\Services\CostCalculator;
use App\Domain\Identification\Services\ProviderRouter;
use App\Domain\Media\Services\ImageProcessor;
use App\Domain\Settings\Services\SettingsService;
use App\Domain\Shared\Support\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Offline accuracy benchmark (doc 08 §12). Folder layout:
 *   {dir}/{Genus species}/photo1.jpg   e.g. eval/Channa micropeltes/001.jpg
 * Reports top-1 / top-3 accuracy against the catalogue, cost and latency.
 * Respects the free-tier daily limit and stops cleanly when it is reached (resume with --skip).
 */
class RunAiEvaluation extends Command
{
    protected $signature = 'fishdex:ai-eval {dir} {--provider=} {--limit=300} {--skip=0} {--country=MY}';

    protected $description = 'Benchmark AI identification accuracy on a labelled image folder';

    public function handle(ProviderRouter $router, CandidateMatcher $matcher, ImageProcessor $images, AiCapacity $capacity, CostCalculator $costs, SettingsService $settings): int
    {
        $country = Country::byIso($this->option('country'));
        $provider = $router->make($this->option('provider') ?: $settings->get('ai.provider'));

        $files = collect(File::directories($this->argument('dir')))
            ->flatMap(fn ($d) => collect(File::files($d))->map(fn ($f) => [basename($d), $f->getPathname()]))
            ->slice((int) $this->option('skip'), (int) $this->option('limit'))->values();

        $n = $top1 = $top3 = $cost = 0;
        $latencies = [];
        $confusions = [];

        foreach ($files as $i => [$label, $path]) {
            if (! $capacity->acquire()['granted']) {
                $this->warn('AI capacity reached. Resume later with --skip='.((int) $this->option('skip') + $i));

                break;
            }

            try {
                $inspected = $images->inspect((string) file_get_contents($path));
                $jpeg = $images->encode($inspected['image'], 1024, 'jpg', 85);
                $result = $provider->identify(new IdentificationInput($jpeg, 'image/jpeg', $country->name));
            } catch (ProviderRateLimited) {
                $this->warn('Provider rate limited. Resume later with --skip='.((int) $this->option('skip') + $i));

                break;
            } catch (Throwable $e) {
                $this->warn("{$path}: {$e->getMessage()}");

                continue;
            }

            $n++;
            $latencies[] = $result->latencyMs;
            $cost += $costs->micros($result->model, $settings->aiTier(), $result->inputTokens, $result->outputTokens);

            $predicted = collect($result->candidates)->map(fn ($c) => NameNormalizer::scientific((string) $c->scientificName))->values();
            $expected = NameNormalizer::scientific($label);

            if (($predicted[0] ?? null) === $expected) {
                $top1++;
            } else {
                $confusions[] = [$label, $predicted[0] ?? '(none)'];
            }
            if ($predicted->take(3)->contains($expected)) {
                $top3++;
            }
        }

        sort($latencies);
        $this->table(['Images', 'Top-1', 'Top-3', 'p50 ms', 'p95 ms', 'Cost USD'], [[
            $n,
            $n ? round($top1 / $n * 100, 1).'%' : '-',
            $n ? round($top3 / $n * 100, 1).'%' : '-',
            $latencies[(int) floor(count($latencies) * 0.5)] ?? '-',
            $latencies[(int) min(count($latencies) - 1, floor(count($latencies) * 0.95))] ?? '-',
            number_format($cost / 1e6, 4),
        ]]);

        if ($confusions) {
            $this->line('Top-1 misses (expected → predicted):');
            $this->table(['Expected', 'Predicted'], array_slice($confusions, 0, 30));
        }

        return self::SUCCESS;
    }
}
