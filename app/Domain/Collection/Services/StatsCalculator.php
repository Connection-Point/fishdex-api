<?php

namespace App\Domain\Collection\Services;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Services\RarityResolver;
use App\Domain\Collection\Models\UserStats;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Decision;
use App\Domain\Shared\Enums\EncounterStatus;
use Illuminate\Support\Facades\DB;

/** Recomputes the denormalised user_stats row. */
class StatsCalculator
{
    public function __construct(private readonly RarityResolver $rarity) {}

    public function recalculate(User $user): UserStats
    {
        $collection = $user->collection()->with('species.family', 'species.habitats')->get();
        $collected = $collection->where('state.value', 'collected');

        $encounters = $user->encounters()->where('status', EncounterStatus::Confirmed);
        $rarityMap = $this->rarity->mapFor($user->country_id);

        $byRarity = $collected->groupBy(fn ($e) => $rarityMap->get($e->species_id)?->code ?? 'unrated')->map->count();

        $byFamily = $collected->groupBy(fn ($e) => $e->species->family->scientific_name)->map->count();

        $byHabitat = [];
        foreach ($collected as $e) {
            foreach ($e->species->habitats as $h) {
                $byHabitat[$h->code] = ($byHabitat[$h->code] ?? 0) + 1;
            }
        }

        $completionBySet = DB::table('card_sets')
            ->where('is_active', true)
            ->get(['id', 'code'])
            ->mapWithKeys(function ($set) use ($collected) {
                $total = DB::table('fish_cards')->where('card_set_id', $set->id)->where('variant', 'standard')->where('is_active', true)->count();
                $owned = DB::table('fish_cards')->where('card_set_id', $set->id)->where('variant', 'standard')
                    ->whereIn('species_id', $collected->pluck('species_id'))->count();

                return [$set->code => ['owned' => $owned, 'total' => $total]];
            });

        return UserStats::query()->updateOrCreate(['user_id' => $user->id], [
            'species_seen' => $collection->count(),
            'species_collected' => $collected->count(),
            'encounters_total' => (clone $encounters)->count(),
            'encounters_released' => (clone $encounters)->where('decision', Decision::Release)->count(),
            'by_rarity' => $byRarity->all(),
            'by_family' => $byFamily->all(),
            'by_habitat' => $byHabitat,
            'completion_by_set' => $completionBySet->all(),
            'last_encounter_at' => (clone $encounters)->max('caught_at'),
            'recalculated_at' => now(),
        ]);
    }

    public function catalogueSize(int $countryId): int
    {
        return FishSpecies::query()->published()
            ->whereExists(fn ($q) => $q->from('species_country_status')->whereColumn('species_country_status.species_id', 'fish_species.id')
                ->where('species_country_status.country_id', $countryId))
            ->count();
    }
}
