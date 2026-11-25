<?php

namespace App\Domain\Identification\Providers;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Identification\Contracts\IdentificationProvider;
use App\Domain\Identification\Data\IdentificationInput;
use App\Domain\Identification\Data\ProviderResult;
use App\Domain\Identification\Data\RawCandidate;
use App\Domain\Identification\Data\RerankInput;
use Throwable;

/**
 * Deterministic provider for local development and tests, so developers don't
 * burn the shared free-tier quota. Tests can queue exact results or exceptions.
 */
class FakeProvider implements IdentificationProvider
{
    /** @var list<ProviderResult|Throwable> */
    private static array $queue = [];

    /** @var list<ProviderResult|Throwable> */
    private static array $rerankQueue = [];

    public static array $calls = [];

    public static function push(ProviderResult|Throwable $result): void
    {
        self::$queue[] = $result;
    }

    public static function pushRerank(ProviderResult|Throwable $result): void
    {
        self::$rerankQueue[] = $result;
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$rerankQueue = [];
        self::$calls = [];
    }

    /** Convenience builder for tests. */
    public static function result(array $candidates, array $flags = [], int $in = 1200, int $out = 300): ProviderResult
    {
        return new ProviderResult(
            array_map(fn ($c) => $c instanceof RawCandidate ? $c : RawCandidate::fromArray($c), $candidates),
            $flags, $in, $out, 5, 'fake-model',
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function identify(IdentificationInput $input): ProviderResult
    {
        self::$calls[] = 'identify';

        if ($next = array_shift(self::$queue)) {
            return $next instanceof Throwable ? throw $next : $next;
        }

        // Default: pretend the first published species was seen with high confidence.
        $species = FishSpecies::query()->published()->orderBy('id')->first();

        return self::result($species ? [[
            'scientific_name' => $species->scientific_name,
            'common_name' => 'fake',
            'confidence' => 0.92,
            'visible_features' => ['fake feature'],
        ]] : [], $species ? [] : ['no_fish']);
    }

    public function rerank(RerankInput $input): ProviderResult
    {
        self::$calls[] = 'rerank';

        if ($next = array_shift(self::$rerankQueue)) {
            return $next instanceof Throwable ? throw $next : $next;
        }

        $first = $input->shortlist[0] ?? null;

        return self::result($first ? [['ref' => $first['ref'], 'confidence' => 0.7]] : []);
    }
}
