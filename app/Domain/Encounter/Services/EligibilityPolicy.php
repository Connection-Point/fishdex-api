<?php

namespace App\Domain\Encounter\Services;

use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Shared\Enums\IdentificationSource;

/**
 * PRD §7.2 — which encounters may produce a collected card.
 */
class EligibilityPolicy
{
    /** Flags that block collection until a reviewer clears them. */
    private const BLOCKING_FLAGS = ['duplicate_image', 'possible_screen_photo', 'not_a_photo'];

    /** @return array{eligible: bool, verified: bool, review_reason: ?string} */
    public function evaluate(?IdentificationRequest $scan, IdentificationSource $source, bool $hasSpecies): array
    {
        if (! $hasSpecies || $source === IdentificationSource::Manual || $scan === null) {
            return ['eligible' => false, 'verified' => false, 'review_reason' => null];
        }

        foreach (self::BLOCKING_FLAGS as $flag) {
            if ($scan->hasFlag($flag)) {
                return [
                    'eligible' => false,
                    'verified' => false,
                    'review_reason' => $flag === 'duplicate_image' ? 'duplicate_image' : 'possible_screen_photo',
                ];
            }
        }

        if ($source === IdentificationSource::UserSelected) {
            return ['eligible' => true, 'verified' => false, 'review_reason' => 'low_confidence_user_pick'];
        }

        $sampled = mt_rand() / mt_getrandmax() < (float) config('fishdex.review.random_sample_ratio', 0.02);

        return ['eligible' => true, 'verified' => true, 'review_reason' => $sampled ? 'random_sample' : null];
    }
}
