<?php

namespace App\Domain\Identification\Services;

use App\Domain\Settings\Services\SettingsService;
use App\Domain\Shared\Enums\ConfidenceBand;
use App\Domain\Shared\Enums\MatchMethod;

/**
 * Backend-owned composite confidence (D-09). The model's self-rating is only one input.
 *
 * final = w_model*model + w_match*match + w_margin*margin + w_agree*agreement - penalties
 */
class ConfidenceScorer
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  list<array{species_id: ?int, method: MatchMethod, match_score: float, model_confidence: float, geo_penalty: float, agreement: float}>  $candidates
     * @return array{candidates: list<array>, band: ConfidenceBand, margin: float}
     */
    public function score(array $candidates, bool $lowQuality): array
    {
        $w = $this->settings->get('confidence.weights', config('fishdex.confidence.weights'));
        $qualityPenalty = $lowQuality ? (float) config('fishdex.confidence.penalties.low_quality') : 0.0;

        // Model-confidence margin between the best two *distinct* species.
        $byModel = collect($candidates)->filter(fn ($c) => $c['species_id'] !== null)
            ->sortByDesc('model_confidence')->values();
        $margin = $byModel->count() > 1
            ? max(0.0, $byModel[0]['model_confidence'] - $byModel[1]['model_confidence'])
            : ($byModel->count() === 1 ? $byModel[0]['model_confidence'] : 0.0);

        $topModelSpecies = $byModel[0]['species_id'] ?? null;

        foreach ($candidates as &$c) {
            if ($c['species_id'] === null) {
                $c['final_confidence'] = 0.0;

                continue;
            }

            $ownMargin = $c['species_id'] === $topModelSpecies ? $margin : 0.0;

            $final = $w['model'] * $c['model_confidence']
                + $w['match'] * $c['match_score']
                + $w['margin'] * min(1.0, $ownMargin)
                + $w['agreement'] * $c['agreement']
                - $c['geo_penalty']
                - $qualityPenalty;

            $c['final_confidence'] = round(max(0.0, min(1.0, $final)), 4);
        }
        unset($c);

        usort($candidates, fn ($a, $b) => $b['final_confidence'] <=> $a['final_confidence']);

        return [
            'candidates' => $candidates,
            'band' => $this->band($candidates[0] ?? null, $margin),
            'margin' => round($margin, 4),
        ];
    }

    private function band(?array $top, float $margin): ConfidenceBand
    {
        if ($top === null || $top['species_id'] === null) {
            return ConfidenceBand::Low;
        }

        $high = (float) $this->settings->get('confidence.high', 0.80);
        $highMargin = (float) $this->settings->get('confidence.high_min_margin', 0.15);
        $medium = (float) $this->settings->get('confidence.medium', 0.55);

        if ($top['final_confidence'] >= $high && $margin >= $highMargin && $top['method']->isStrong()) {
            return ConfidenceBand::High;
        }

        return $top['final_confidence'] >= $medium ? ConfidenceBand::Medium : ConfidenceBand::Low;
    }
}
