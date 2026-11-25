<?php

namespace App\Domain\Review\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Collection\Services\CollectionAggregator;
use App\Domain\Encounter\Events\EncounterChanged;
use App\Domain\Review\Models\ReviewItem;
use App\Domain\Shared\Enums\Decision;
use App\Domain\Shared\Enums\EncounterStatus;
use App\Domain\Shared\Enums\IdentificationSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reviewer outcome:
 *  confirmed → identification stands; encounter becomes verified and collect-eligible
 *  corrected → species changed (expert_corrected), both species' collection rows rebuilt
 *  rejected  → not a valid catch (e.g. internet photo): not collectable, removed from dex
 */
class ResolveReview
{
    public function __construct(private readonly CollectionAggregator $collections) {}

    public function handle(ReviewItem $item, string $outcome, Admin $admin, ?FishSpecies $correctSpecies = null, ?string $notes = null): ReviewItem
    {
        if (! in_array($outcome, ['confirmed', 'corrected', 'rejected'], true)) {
            throw new InvalidArgumentException("Unknown outcome {$outcome}");
        }
        if ($outcome === 'corrected' && ! $correctSpecies) {
            throw new InvalidArgumentException('A corrected review needs the correct species.');
        }

        return DB::transaction(function () use ($item, $outcome, $admin, $correctSpecies, $notes) {
            $encounter = $item->encounter;
            $affected = array_filter([$encounter?->species_id]);

            if ($encounter) {
                match ($outcome) {
                    'confirmed' => $encounter->forceFill(['is_verified' => true, 'collect_eligible' => true])->save(),
                    'corrected' => $encounter->forceFill([
                        'species_id' => $correctSpecies->id,
                        'status' => EncounterStatus::Confirmed,
                        'identification_source' => IdentificationSource::ExpertCorrected,
                        'is_verified' => true,
                        'collect_eligible' => true,
                    ])->save(),
                    'rejected' => $encounter->forceFill([
                        'collect_eligible' => false,
                        'is_verified' => false,
                        'status' => EncounterStatus::Rejected,
                        'decision' => $encounter->decision === Decision::Collect ? Decision::Release : $encounter->decision,
                    ])->save(),
                };

                $affected[] = $encounter->species_id;
                foreach (array_unique(array_filter($affected)) as $speciesId) {
                    $this->collections->rebuild($encounter->user_id, $speciesId);
                }
            }

            $item->forceFill([
                'status' => $outcome,
                'resolved_species_id' => $correctSpecies?->id ?? $encounter?->species_id,
                'assigned_admin_id' => $admin->id,
                'resolution_notes' => $notes,
                'resolved_at' => now(),
            ])->save();

            if ($encounter) {
                event(new EncounterChanged($encounter->user_id));
            }

            return $item;
        });
    }
}
