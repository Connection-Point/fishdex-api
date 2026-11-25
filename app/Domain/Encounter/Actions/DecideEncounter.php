<?php

namespace App\Domain\Encounter\Actions;

use App\Domain\Collection\Models\UserSpeciesCollection;
use App\Domain\Collection\Services\CollectionAggregator;
use App\Domain\Encounter\Events\EncounterDecided;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Shared\Enums\CollectionState;
use App\Domain\Shared\Enums\Decision;
use App\Domain\Shared\Support\Problem;
use Illuminate\Support\Facades\DB;

/**
 * Step 9: Collect or Release. Rules:
 *  - only confirmed encounters with a species can be decided,
 *  - collect requires collect_eligible,
 *  - release → collect is allowed once; collect → release is not (keeps counts simple),
 *  - repeating the same decision is idempotent.
 */
class DecideEncounter
{
    public function __construct(private readonly CollectionAggregator $collections) {}

    /** @return array{encounter: FishEncounter, entry: UserSpeciesCollection, is_new_species: bool, is_personal_best: bool} */
    public function handle(FishEncounter $encounter, Decision $decision): array
    {
        if (! $encounter->isConfirmed()) {
            throw Problem::make(422, 'not_collect_eligible', 'Only confirmed fish can be collected or released.');
        }
        if ($decision === Decision::Collect && ! $encounter->collect_eligible) {
            throw Problem::make(422, 'not_collect_eligible', 'This catch can be kept in your journal, but it can\'t be added to your FishDex.');
        }
        if ($encounter->decision === Decision::Collect && $decision === Decision::Release) {
            throw Problem::make(409, 'decision_final', 'This fish is already in your FishDex.');
        }

        return DB::transaction(function () use ($encounter, $decision) {
            $before = UserSpeciesCollection::query()
                ->where('user_id', $encounter->user_id)->where('species_id', $encounter->species_id)
                ->lockForUpdate()->first();

            $wasCollected = $before?->state === CollectionState::Collected;
            $prevBestLength = $before?->best_length_cm;
            $prevBestWeight = $before?->best_weight_kg;

            if ($encounter->decision !== $decision) {
                $encounter->forceFill(['decision' => $decision, 'decision_at' => now()])->save();
            }

            $entry = $this->collections->rebuild($encounter->user_id, $encounter->species_id);

            $isPb = ($encounter->length_cm && $entry->best_length_encounter_id === $encounter->id && $prevBestLength !== null && $encounter->length_cm > $prevBestLength)
                || ($encounter->weight_kg && $entry->best_weight_encounter_id === $encounter->id && $prevBestWeight !== null && $encounter->weight_kg > $prevBestWeight);

            $isNew = ! $wasCollected && $entry->state === CollectionState::Collected;

            event(new EncounterDecided($encounter, $isNew));

            return [
                'encounter' => $encounter,
                'entry' => $entry,
                'is_new_species' => $isNew,
                'is_personal_best' => $isPb,
            ];
        });
    }
}
