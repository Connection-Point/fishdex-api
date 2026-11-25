<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Models\FishSpecies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Compact, versioned catalogue for the app's offline cache (ETag = version).
 */
class CatalogueSnapshot
{
    public function __construct(private readonly RarityResolver $rarity) {}

    /** Changes whenever species, names, rarity or cards change. */
    public function version(int $countryId): string
    {
        return Cache::remember("catalogue:version:{$countryId}", 60, function () use ($countryId) {
            $parts = [
                DB::table('fish_species')->max('updated_at'),
                DB::table('fish_species')->count(),
                DB::table('species_names')->max('updated_at'),
                DB::table('species_rarities')->where('country_id', $countryId)->max('updated_at'),
                DB::table('fish_cards')->max('updated_at'),
                DB::table('reference_images')->max('updated_at'),
            ];

            return substr(sha1(implode('|', array_map('strval', $parts))), 0, 16);
        });
    }

    public function build(int $countryId): array
    {
        $version = $this->version($countryId);

        return Cache::remember("catalogue:snapshot:{$countryId}:{$version}", 3600, function () use ($countryId, $version) {
            $rarity = $this->rarity->mapFor($countryId);

            $species = FishSpecies::query()->published()
                ->whereExists(fn ($q) => $q->from('species_country_status')
                    ->whereColumn('species_country_status.species_id', 'fish_species.id')
                    ->where('species_country_status.country_id', $countryId))
                ->with(['names', 'family', 'habitats', 'images' => fn ($q) => $q->where('is_verified', true), 'cards.set', 'countryStatuses'])
                ->orderBy('scientific_name')
                ->get()
                ->map(fn (FishSpecies $s) => [
                    'id' => $s->public_id,
                    'scientific_name' => $s->scientific_name,
                    'family' => $s->family->scientific_name,
                    'water_types' => $s->water_types,
                    'habitats' => $s->habitats->pluck('code'),
                    'origin' => $s->countryStatuses->firstWhere('country_id', $countryId)?->origin,
                    'rarity' => $rarity->get($s->id)?->code,
                    'names' => $s->names
                        ->filter(fn ($n) => $n->country_id === null || $n->country_id === $countryId)
                        ->map(fn ($n) => ['n' => $n->name, 'l' => $n->locale, 'p' => $n->is_primary])->values(),
                    'thumbnail_url' => ($s->images->firstWhere('is_primary', true) ?? $s->images->first())?->url(),
                    'cards' => $s->cards->where('is_active', true)->map(fn ($c) => ['set' => $c->set->code, 'number' => $c->card_number, 'variant' => $c->variant])->values(),
                ]);

            // Plain arrays only (cache.serializable_classes = false).
            return json_decode(json_encode(['version' => $version, 'country' => $countryId, 'generated_at' => now()->toIso8601String(), 'species' => $species]), true);
        });
    }
}
