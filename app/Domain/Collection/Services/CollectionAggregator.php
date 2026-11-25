<?php

namespace App\Domain\Collection\Services;

use App\Domain\Cards\Services\CardResolver;
use App\Domain\Catalogue\Services\RarityResolver;
use App\Domain\Collection\Models\UserSpeciesCollection;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Shared\Enums\CollectionState;
use App\Domain\Shared\Enums\Decision;
use App\Domain\Shared\Enums\EncounterStatus;

/**
 * Rebuilds the single (user, species) collection row from that user's encounters.
 * Idempotent: always derives the row from source data, so it can never drift for long.
 */
class CollectionAggregator
{
    public function __construct(
        private readonly CardResolver $cards,
        private readonly RarityResolver $rarity,
    ) {}

    public function rebuild(int $userId, int $speciesId): ?UserSpeciesCollection
    {
        $encounters = FishEncounter::query()
            ->where('user_id', $userId)
            ->where('species_id', $speciesId)
            ->where('status', EncounterStatus::Confirmed)
            ->orderBy('caught_at')->orderBy('id')
            ->get();

        $entry = UserSpeciesCollection::query()->where('user_id', $userId)->where('species_id', $speciesId)->first();

        if ($encounters->isEmpty()) {
            $entry?->delete();

            return null;
        }

        $collected = $encounters->where('decision', Decision::Collect);
        $first = $encounters->first();
        $firstCollected = $collected->first();
        $bestLength = $encounters->whereNotNull('length_cm')->sortByDesc('length_cm')->first();
        $bestWeight = $encounters->whereNotNull('weight_kg')->sortByDesc('weight_kg')->first();
        $countryId = $first->country_id;

        $entry ??= new UserSpeciesCollection(['user_id' => $userId, 'species_id' => $speciesId]);

        $state = $collected->isNotEmpty() ? CollectionState::Collected : CollectionState::Seen;

        // Snapshot rarity at first collection; later rebalancing doesn't rewrite history.
        $snapshot = $entry->rarity_tier_id_snapshot;
        if ($state === CollectionState::Collected && $snapshot === null) {
            $snapshot = $this->rarity->tierFor($speciesId, $countryId)?->id;
        }
        if ($state === CollectionState::Seen) {
            $snapshot = null;
        }

        $entry->fill([
            'state' => $state,
            'card_id' => $entry->card_id ?? $this->cards->forSpecies($speciesId, $countryId)?->id,
            'rarity_tier_id_snapshot' => $snapshot,
            'first_seen_at' => $first->caught_at,
            'first_collected_at' => $firstCollected?->decision_at ?? null,
            'first_encounter_id' => $first->id,
            'first_collected_encounter_id' => $firstCollected?->id,
            'latest_encounter_id' => $encounters->sortByDesc('caught_at')->first()->id,
            'encounter_count' => $encounters->count(),
            'collected_count' => $collected->count(),
            'released_count' => $encounters->where('decision', Decision::Release)->count(),
            'best_length_cm' => $bestLength?->length_cm,
            'best_length_encounter_id' => $bestLength?->id,
            'best_weight_kg' => $bestWeight?->weight_kg,
            'best_weight_encounter_id' => $bestWeight?->id,
        ]);

        // Cover photo must still belong to one of these encounters.
        if ($entry->cover_media_id && ! $encounters->pluck('primary_media_id')->contains($entry->cover_media_id)) {
            $entry->cover_media_id = null;
        }

        $entry->save();

        return $entry;
    }

    /** Full rebuild for one user (nightly reconciliation, review corrections). */
    public function rebuildUser(int $userId): void
    {
        $speciesIds = FishEncounter::withTrashed()->where('user_id', $userId)->whereNotNull('species_id')->distinct()->pluck('species_id')
            ->merge(UserSpeciesCollection::query()->where('user_id', $userId)->pluck('species_id'))
            ->unique();

        foreach ($speciesIds as $speciesId) {
            $this->rebuild($userId, (int) $speciesId);
        }
    }
}
