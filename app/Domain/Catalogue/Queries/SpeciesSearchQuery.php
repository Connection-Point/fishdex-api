<?php

namespace App\Domain\Catalogue\Queries;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Shared\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue search: one query finds "toman", "giant snakehead", "ikan toman"
 * and "Channa micropeltes" (English, Malay, local aliases, scientific).
 */
class SpeciesSearchQuery
{
    public function build(int $countryId, array $filters): Builder
    {
        $query = FishSpecies::query()->published()
            ->whereExists(fn ($q) => $q->from('species_country_status')
                ->whereColumn('species_country_status.species_id', 'fish_species.id')
                ->where('species_country_status.country_id', $countryId)
                ->where('species_country_status.is_present', true));

        if (filled($filters['q'] ?? null)) {
            $q = trim($filters['q']);
            $common = NameNormalizer::common($q);
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
            $commonLike = '%'.str_replace(['%', '_'], ['\%', '\_'], $common).'%';

            $query->where(function (Builder $w) use ($like, $commonLike, $countryId) {
                $w->whereRaw('LOWER(fish_species.scientific_name) LIKE ?', [$like])
                    ->orWhereExists(fn ($s) => $s->from('species_names')
                        ->whereColumn('species_names.species_id', 'fish_species.id')
                        ->where(fn ($c) => $c->whereNull('species_names.country_id')->orWhere('species_names.country_id', $countryId))
                        ->where('species_names.normalized_name', 'like', $commonLike))
                    ->orWhereExists(fn ($s) => $s->from('species_synonyms')
                        ->whereColumn('species_synonyms.species_id', 'fish_species.id')
                        ->where('species_synonyms.normalized_name', 'like', $like));
            });
        }

        if (filled($filters['family'] ?? null)) {
            $query->whereHas('family', fn ($f) => $f->where('scientific_name', $filters['family']));
        }

        if (filled($filters['habitat'] ?? null)) {
            $query->whereHas('habitats', fn ($h) => $h->where('code', $filters['habitat']));
        }

        if (filled($filters['water_type'] ?? null)) {
            $query->where('water_types', 'like', '%"'.preg_replace('/[^a-z]/', '', $filters['water_type']).'"%');
        }

        if (filled($filters['origin'] ?? null)) {
            $query->whereExists(fn ($q) => $q->from('species_country_status')
                ->whereColumn('species_country_status.species_id', 'fish_species.id')
                ->where('species_country_status.country_id', $countryId)
                ->where('species_country_status.origin', $filters['origin']));
        }

        if (filled($filters['region'] ?? null)) {
            $query->whereHas('regions', fn ($r) => $r->where('regions.code', $filters['region']));
        }

        if (filled($filters['rarity'] ?? null)) {
            $now = now()->toDateTimeString();
            $dayStart = now()->startOfDay()->toDateTimeString();
            $query->whereExists(fn ($q) => $q->from('species_rarities')
                ->join('rarity_tiers', 'rarity_tiers.id', '=', 'species_rarities.rarity_tier_id')
                ->whereColumn('species_rarities.species_id', 'fish_species.id')
                ->where('species_rarities.country_id', $countryId)
                ->where('species_rarities.effective_from', '<=', $now)
                ->where(fn ($w) => $w->whereNull('species_rarities.effective_to')->orWhere('species_rarities.effective_to', '>=', $dayStart))
                ->where('rarity_tiers.code', $filters['rarity']));
        }

        return match ($filters['sort'] ?? 'name') {
            'card_number' => $query->orderBy(
                DB::table('fish_cards')->select('card_number')->whereColumn('fish_cards.species_id', 'fish_species.id')
                    ->where('variant', 'standard')->orderBy('card_set_id')->limit(1)
            )->orderBy('fish_species.id'),
            default => $query->orderBy('fish_species.scientific_name')->orderBy('fish_species.id'),
        };
    }
}
