<?php

namespace App\Domain\Identification\Services;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Identification\Contracts\IdentificationProvider;
use App\Domain\Identification\Data\IdentificationInput;
use App\Domain\Identification\Data\ProviderResult;
use App\Domain\Identification\Data\RerankInput;
use App\Domain\Identification\Events\ScanCompleted;
use App\Domain\Identification\Exceptions\ProviderException;
use App\Domain\Identification\Exceptions\ProviderRateLimited;
use App\Domain\Identification\Models\IdentificationAttempt;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identification\Prompts\IdentificationPrompts;
use App\Domain\Media\Services\MediaStore;
use App\Domain\Settings\Services\SettingsService;
use App\Domain\Shared\Enums\ConfidenceBand;
use App\Domain\Shared\Enums\MatchMethod;
use App\Domain\Shared\Enums\ScanOutcome;
use App\Domain\Shared\Enums\ScanStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Steps 4–7 of the AI workflow (doc 08): identify → extract → match → score.
 * Called by the RunIdentification job. Never assigns rarity.
 */
class IdentificationPipeline
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ProviderRouter $router,
        private readonly AiCapacity $capacity,
        private readonly AiBudgetGuard $budget,
        private readonly CostCalculator $costs,
        private readonly CandidateMatcher $matcher,
        private readonly GeoPlausibility $geo,
        private readonly ConfidenceScorer $scorer,
        private readonly ShortlistBuilder $shortlists,
        private readonly MediaStore $media,
    ) {}

    public function run(IdentificationRequest $scan): void
    {
        $scan->refresh();
        if (! in_array($scan->status, [ScanStatus::Queued, ScanStatus::WaitingCapacity, ScanStatus::Processing], true)) {
            return;
        }

        if (! $this->budget->allowsNewScans()) {
            $this->waitForCapacity($scan, now()->addMinutes(15)->toImmutable(), 'ai_paused');

            return;
        }

        $flags = $scan->flags ?? [];
        if ($this->isCrossUserDuplicate($scan)) {
            $flags[] = 'duplicate_image';
        }

        if ($this->reuseOwnPreviousResult($scan, $flags)) {
            return;
        }

        $slot = $this->capacity->acquire();
        if (! $slot['granted']) {
            $this->waitForCapacity($scan, $slot['retry_at'], $slot['reason']);

            return;
        }

        $scan->forceFill([
            'status' => ScanStatus::Processing,
            'started_at' => $scan->started_at ?? now(),
            'attempt_count' => $scan->attempt_count + 1,
            'ai_tier' => $this->settings->aiTier(),
        ])->save();

        $input = $this->input($scan);

        // ---- Pass 1: open identification (primary, then fallback provider) ----
        $pass1 = $this->callProviders($scan, 'open', fn (IdentificationProvider $p) => $p->identify($input));
        if ($pass1 === null) {
            return; // rate limited → waiting_capacity already set
        }

        $flags = array_values(array_unique(array_merge($flags, $this->mapFlags($pass1))));

        if ($outcome = $this->unusableOutcome($pass1)) {
            $this->complete($scan, $outcome, null, [], $flags);

            return;
        }

        // ---- Match to verified catalogue ----
        $rows = [];
        foreach ($pass1->candidates as $raw) {
            $m = $this->matcher->match($raw, $scan->country_id);
            $rows[] = $this->row($raw, $m, $scan, agreement: 0.5);
        }

        // ---- Pass 2: constrained re-rank when the result is weak ----
        if ($this->needsPass2($rows) && $this->pass2Enabled() && $this->capacity->acquire()['granted']) {
            $rows = $this->pass2($scan, $input, $pass1, $rows) ?? $rows;
        }

        $rows = $this->dedupeBySpecies($rows);
        $scored = $this->scorer->score($rows, $pass1->hasFlag('low_quality'));

        $matched = array_values(array_filter($scored['candidates'], fn ($c) => $c['species_id'] !== null));
        $outcome = $matched ? ScanOutcome::Identified : ScanOutcome::NotInCatalogue;

        $this->complete($scan, $outcome, $matched ? $scored['band'] : ConfidenceBand::Low, $scored['candidates'], $flags);
    }

    public function markFailed(IdentificationRequest $scan, string $code): void
    {
        $scan->forceFill(['status' => ScanStatus::Failed, 'error_code' => $code, 'completed_at' => now()])->save();
        event(new ScanCompleted($scan));
    }

    // ------------------------------------------------------------------

    private function callProviders(IdentificationRequest $scan, string $pass, callable $call): ?ProviderResult
    {
        $lastError = null;

        foreach ($this->router->providers() as $provider) {
            try {
                $result = $call($provider);
                $this->logAttempt($scan, $provider, $pass, 'success', $result);
                $this->router->recordSuccess($provider->name());

                return $result;
            } catch (ProviderRateLimited $e) {
                $this->logAttempt($scan, $provider, $pass, $e->attemptStatus(), null, $e);
                $retryAt = $this->capacity->recordRateLimited();
                $this->waitForCapacity($scan, $retryAt, 'provider_rate_limited');

                return null;
            } catch (ProviderException $e) {
                $this->logAttempt($scan, $provider, $pass, $e->attemptStatus(), null, $e);
                $this->router->recordFailure($provider->name());
                $lastError = $e;
            }
        }

        // Let the queue retry; RunIdentification::failed() marks the scan failed after the last try.
        throw new RuntimeException('All AI providers failed: '.($lastError?->getMessage() ?? 'no provider configured'));
    }

    private function pass2(IdentificationRequest $scan, IdentificationInput $input, ProviderResult $pass1, array $rows): ?array
    {
        $matchedIds = array_values(array_filter(array_column($rows, 'species_id')));
        $shortlist = $this->shortlists->build($pass1->candidates, $matchedIds, $scan->country_id);
        if (! $shortlist['items']) {
            return null;
        }

        try {
            $pass2 = $this->callProviders($scan, 'constrained', fn (IdentificationProvider $p) => $p->rerank(new RerankInput($input, $shortlist['items'])));
        } catch (RuntimeException) {
            return null; // pass 2 is optional — keep pass-1 result
        }
        if ($pass2 === null) {
            // Rate-limited during pass 2: don't leave the scan waiting, finish with pass 1.
            $scan->forceFill(['status' => ScanStatus::Processing, 'expected_after' => null, 'error_code' => null])->save();

            return null;
        }

        $picks = [];
        foreach ($pass2->candidates as $raw) {
            $m = $this->matcher->match($raw, $scan->country_id, $shortlist['refs']);
            if ($m['species_id'] !== null) {
                $picks[$m['species_id']] ??= [$raw, $m];
            }
        }
        $topPick = array_key_first($picks);

        foreach ($rows as &$row) {
            if ($row['species_id'] !== null) {
                $row['agreement'] = $row['species_id'] === $topPick ? 1.0 : 0.0;
            }
        }
        unset($row);

        foreach ($picks as $speciesId => [$raw, $m]) {
            if (! in_array($speciesId, $matchedIds, true)) {
                $rows[] = $this->row($raw, $m, $scan, agreement: $speciesId === $topPick ? 1.0 : 0.0);
            }
        }

        return $rows;
    }

    private function row($raw, array $m, IdentificationRequest $scan, float $agreement): array
    {
        $geo = $m['species_id'] ? $this->geo->assess($m['species_id'], $scan->country_id, $scan->region_id) : ['plausible' => null, 'penalty' => 0.0];

        return [
            'species_id' => $m['species_id'],
            'method' => $m['method'],
            'match_score' => $m['score'],
            'model_confidence' => $raw->confidence,
            'geo_penalty' => $geo['penalty'],
            'geo_plausible' => $geo['plausible'],
            'agreement' => $agreement,
            'raw_label' => $raw->commonName,
            'raw_scientific_name' => $raw->scientificName,
            'features' => $raw->features,
        ];
    }

    private function dedupeBySpecies(array $rows): array
    {
        $out = [];
        $unmatched = [];
        foreach ($rows as $row) {
            if ($row['species_id'] === null) {
                $unmatched[] = $row;

                continue;
            }
            $existing = $out[$row['species_id']] ?? null;
            if ($existing === null || $row['model_confidence'] > $existing['model_confidence']) {
                if ($existing) {
                    $row['agreement'] = max($row['agreement'], $existing['agreement']);
                }
                $out[$row['species_id']] = $row;
            }
        }

        return array_merge(array_values($out), $unmatched);
    }

    private function needsPass2(array $rows): bool
    {
        $matched = array_values(array_filter($rows, fn ($r) => $r['species_id'] !== null));
        if (! $matched) {
            return true;
        }
        usort($matched, fn ($a, $b) => $b['model_confidence'] <=> $a['model_confidence']);

        $margin = $matched[0]['model_confidence'] - ($matched[1]['model_confidence'] ?? 0.0);

        return ! $matched[0]['method']->isStrong() || $margin < (float) $this->settings->get('confidence.high_min_margin', 0.15);
    }

    private function pass2Enabled(): bool
    {
        return (bool) $this->settings->get('ai.pass2_enabled.'.$this->settings->aiTier(), false);
    }

    private function complete(IdentificationRequest $scan, ScanOutcome $outcome, ?ConfidenceBand $band, array $candidates, array $flags): void
    {
        DB::transaction(function () use ($scan, $outcome, $band, $candidates, $flags) {
            $scan->candidates()->delete();

            $rank = 0;
            foreach (array_slice($candidates, 0, 5) as $c) {
                $scan->candidates()->create([
                    'rank' => ++$rank,
                    'raw_label' => $c['raw_label'],
                    'raw_scientific_name' => $c['raw_scientific_name'],
                    'model_confidence' => round($c['model_confidence'], 4),
                    'matched_species_id' => $c['species_id'],
                    'match_method' => $c['method'],
                    'match_score' => $c['match_score'],
                    'geo_plausible' => $c['geo_plausible'],
                    'final_confidence' => $c['final_confidence'] ?? 0,
                    'visible_features' => $c['features'],
                ]);
            }

            $top = collect($candidates)->firstWhere('species_id', '!=', null);

            $scan->forceFill([
                'status' => ScanStatus::Completed,
                'outcome' => $outcome,
                'confidence_band' => $band,
                'top_species_id' => $top['species_id'] ?? null,
                'top_confidence' => $top['final_confidence'] ?? null,
                'flags' => array_values(array_unique($flags)) ?: null,
                'total_cost_usd_micros' => (int) $scan->attempts()->sum('cost_usd_micros'),
                'completed_at' => now(),
                'expected_after' => null,
                'error_code' => null,
            ])->save();
        });

        event(new ScanCompleted($scan->fresh()));
    }

    private function waitForCapacity(IdentificationRequest $scan, $retryAt, ?string $reason): void
    {
        $scan->forceFill([
            'status' => ScanStatus::WaitingCapacity,
            'expected_after' => $retryAt,
            'error_code' => $reason,
        ])->save();
    }

    private function logAttempt(IdentificationRequest $scan, IdentificationProvider $provider, string $pass, string $status, ?ProviderResult $result, ?ProviderException $e = null): void
    {
        $tier = $this->settings->aiTier();
        $in = $result?->inputTokens ?? $e?->inputTokens ?? 0;
        $out = $result?->outputTokens ?? $e?->outputTokens ?? 0;
        $model = $result?->model ?? $provider->model();

        IdentificationAttempt::create([
            'identification_request_id' => $scan->id,
            'pass' => $pass,
            'provider' => $provider->name(),
            'model' => $model,
            'ai_tier' => $tier,
            'prompt_version' => $pass === 'open' ? IdentificationPrompts::OPEN_VERSION : IdentificationPrompts::RERANK_VERSION,
            'status' => $status,
            'http_status' => $e?->httpStatus ?? ($result ? 200 : null),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost_usd_micros' => $this->costs->micros($model, $tier, $in, $out),
            'latency_ms' => $result?->latencyMs ?? $e?->latencyMs ?? 0,
            'response_json' => $result?->parsed,
            'error_message' => $e ? mb_substr($e->getMessage(), 0, 500) : null,
        ]);
    }

    private function input(IdentificationRequest $scan): IdentificationInput
    {
        $scan->loadMissing(['media', 'region']);

        return new IdentificationInput(
            imageBytes: $this->media->bytes($scan->media, 'ai'),
            mime: 'image/jpeg',
            countryName: Country::query()->whereKey($scan->country_id)->value('name') ?? 'Malaysia',
            regionName: $scan->region?->name,
            encounterType: $scan->encounter_type,
        );
    }

    /** @return list<string> */
    private function mapFlags(ProviderResult $r): array
    {
        $map = ['screen_or_print' => 'possible_screen_photo', 'not_a_photo' => 'not_a_photo', 'low_quality' => 'low_quality', 'multiple_fish' => 'multiple_fish'];

        return array_values(array_filter(array_map(fn ($f) => $map[$f] ?? null, $r->flags)));
    }

    private function unusableOutcome(ProviderResult $r): ?ScanOutcome
    {
        if ($r->hasFlag('no_fish')) {
            return ScanOutcome::NoFish;
        }
        if ($r->hasFlag('not_a_photo')) {
            return ScanOutcome::UnsupportedImage;
        }
        if (! $r->candidates) {
            return $r->hasFlag('multiple_fish') ? ScanOutcome::MultipleFish : ($r->hasFlag('low_quality') ? ScanOutcome::UnsupportedImage : null);
        }

        return null;
    }

    private function isCrossUserDuplicate(IdentificationRequest $scan): bool
    {
        $scan->loadMissing('media');

        return DB::table('media')
            ->where('sha256', $scan->media->sha256)
            ->where('user_id', '!=', $scan->user_id)
            ->exists();
    }

    /** Same user re-scanning the same image: reuse the earlier result, no new AI cost. */
    private function reuseOwnPreviousResult(IdentificationRequest $scan, array $flags): bool
    {
        $previous = IdentificationRequest::query()
            ->where('user_id', $scan->user_id)
            ->where('id', '!=', $scan->id)
            ->where('status', ScanStatus::Completed)
            ->where('created_at', '>=', now()->subDays((int) config('fishdex.ai.dedupe_days')))
            ->whereHas('media', fn ($q) => $q->where('sha256', $scan->media->sha256))
            ->with('candidates')
            ->latest('id')
            ->first();

        if (! $previous) {
            return false;
        }

        $candidates = $previous->candidates->map(fn ($c) => [
            'species_id' => $c->matched_species_id,
            'method' => $c->match_method ?? MatchMethod::None,
            'match_score' => $c->match_score,
            'model_confidence' => $c->model_confidence ?? 0,
            'geo_plausible' => $c->geo_plausible,
            'final_confidence' => $c->final_confidence,
            'raw_label' => $c->raw_label,
            'raw_scientific_name' => $c->raw_scientific_name,
            'features' => $c->visible_features ?? [],
        ])->all();

        $this->complete($scan, $previous->outcome, $previous->confidence_band, $candidates, array_merge($flags, $previous->flags ?? [], ['reused_result']));

        return true;
    }
}
