<?php

namespace App\Domain\Cards\Services;

use App\Domain\Cards\Models\FishCard;
use Illuminate\Support\Collection;

/** Which card represents a species for a country (newest active standard card). */
class CardResolver
{
    public function forSpecies(int $speciesId, int $countryId): ?FishCard
    {
        return FishCard::query()
            ->where('species_id', $speciesId)
            ->where('variant', 'standard')
            ->where('is_active', true)
            ->whereHas('set', fn ($q) => $q->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $countryId)))
            ->with(['set', 'artwork'])
            ->orderByDesc('card_set_id')
            ->first();
    }

    /** @return Collection<int, FishCard> keyed by species_id */
    public function forSpeciesMany(array $speciesIds, int $countryId): Collection
    {
        return FishCard::query()
            ->whereIn('species_id', $speciesIds)
            ->where('variant', 'standard')
            ->where('is_active', true)
            ->whereHas('set', fn ($q) => $q->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $countryId)))
            ->with(['set', 'artwork'])
            ->orderBy('card_set_id')
            ->get()
            ->keyBy('species_id');
    }
}
