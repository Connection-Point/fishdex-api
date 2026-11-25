<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Catalogue\Models\SpeciesRarity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Current admin-assigned rarity per (species, country). AI never sets this (D-06).
 */
class RarityResolver
{
    /** @var array<int, Collection<int, RarityTier>> */
    private array $memo = [];

    public function tierFor(int $speciesId, int $countryId): ?RarityTier
    {
        return $this->mapFor($countryId)->get($speciesId);
    }

    /** @return Collection<int, RarityTier> keyed by species_id */
    public function mapFor(int $countryId): Collection
    {
        if (isset($this->memo[$countryId])) {
            return $this->memo[$countryId];
        }

        // Only plain arrays go into the cache: config('cache.serializable_classes') is false,
        // so cached objects would come back as __PHP_Incomplete_Class.
        $tierIdsBySpecies = Cache::remember("rarity:map:{$countryId}", 300, function () use ($countryId) {
            $now = now()->toDateTimeString();
            $dayStart = now()->startOfDay()->toDateTimeString();

            return SpeciesRarity::query()
                ->where('country_id', $countryId)
                ->where('effective_from', '<=', $now)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $dayStart))
                ->orderBy('effective_from')
                ->pluck('rarity_tier_id', 'species_id')
                ->all();
        });

        $tiers = RarityTier::all()->keyBy('id');

        return $this->memo[$countryId] = collect($tierIdsBySpecies)->map(fn ($tierId) => $tiers->get($tierId))->filter();
    }

    public function flush(int $countryId): void
    {
        unset($this->memo[$countryId]);
        Cache::forget("rarity:map:{$countryId}");
    }

    /** Close the current assignment and open a new one (history is preserved). */
    public function assign(int $speciesId, int $countryId, int $tierId, ?string $rationale = null, ?int $adminId = null, string $basis = 'editorial'): SpeciesRarity
    {
        $today = now()->toDateString();

        SpeciesRarity::query()
            ->where('species_id', $speciesId)->where('country_id', $countryId)->whereNull('effective_to')
            ->update(['effective_to' => now()->subDay()->toDateString()]);

        $rarity = SpeciesRarity::create([
            'species_id' => $speciesId,
            'country_id' => $countryId,
            'rarity_tier_id' => $tierId,
            'basis' => $basis,
            'rationale' => $rationale,
            'effective_from' => $today,
            'set_by_admin_id' => $adminId,
        ]);

        $this->flush($countryId);

        return $rarity;
    }
}
