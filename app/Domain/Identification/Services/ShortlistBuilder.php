<?php

namespace App\Domain\Identification\Services;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Identification\Data\RawCandidate;
use App\Domain\Shared\Support\NameNormalizer;

/**
 * Builds the pass-2 shortlist: catalogue species from the genera/families the AI
 * pointed at, plus curated look-alikes, limited to species present in the country.
 */
class ShortlistBuilder
{
    /**
     * @param  list<RawCandidate>  $raw
     * @param  list<int>  $matchedSpeciesIds
     * @return array{items: list<array>, refs: array<string, int>}
     */
    public function build(array $raw, array $matchedSpeciesIds, int $countryId, string $locale = 'en'): array
    {
        $limit = (int) config('fishdex.ai.pass2_shortlist_size', 25);

        $genera = collect($raw)->map(fn ($c) => $c->scientificName ? ucfirst(NameNormalizer::genus($c->scientificName)) : null)
            ->filter()->unique()->values()->all();

        $matched = FishSpecies::query()->whereIn('id', $matchedSpeciesIds)->get(['id', 'family_id', 'lookalike_species_ids']);
        $familyIds = $matched->pluck('family_id')->unique()->all();
        $lookalikes = $matched->pluck('lookalike_species_ids')->flatten()->filter()->all();

        if (! $genera && ! $familyIds && ! $lookalikes) {
            return ['items' => [], 'refs' => []];
        }

        $species = FishSpecies::query()->published()
            ->whereExists(fn ($q) => $q->from('species_country_status')
                ->whereColumn('species_country_status.species_id', 'fish_species.id')
                ->where('species_country_status.country_id', $countryId))
            ->where(function ($q) use ($genera, $familyIds, $lookalikes, $matchedSpeciesIds) {
                $q->whereIn('genus', $genera ?: ['-'])
                    ->orWhereIn('family_id', $familyIds ?: [0])
                    ->orWhereIn('id', array_merge($lookalikes, $matchedSpeciesIds) ?: [0]);
            })
            ->with(['names' => fn ($q) => $q->whereIn('locale', ['en', 'ms'])])
            ->orderByRaw('CASE WHEN id IN ('.implode(',', array_map('intval', $matchedSpeciesIds ?: [0])).') THEN 0 ELSE 1 END')
            ->limit($limit)
            ->get();

        $items = [];
        $refs = [];
        foreach ($species as $s) {
            $ref = $s->public_id;
            $refs[$ref] = $s->id;
            $items[] = [
                'ref' => $ref,
                'scientific_name' => $s->scientific_name,
                'common_names' => $s->names->pluck('name')->unique()->take(4)->values()->all(),
                'notes' => $s->identification_notes ? mb_substr($s->identification_notes, 0, 300) : null,
            ];
        }

        return ['items' => $items, 'refs' => $refs];
    }
}
