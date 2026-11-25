<?php

namespace App\Domain\Identification\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penalises candidates that are not recorded in the user's country/state.
 * Unknown regional distribution is never penalised.
 */
class GeoPlausibility
{
    /** @return array{plausible: bool, penalty: float} */
    public function assess(int $speciesId, int $countryId, ?int $regionId): array
    {
        $penalties = config('fishdex.confidence.penalties');

        $inCountry = DB::table('species_country_status')
            ->where('species_id', $speciesId)
            ->where('country_id', $countryId)
            ->where('is_present', true)
            ->exists();

        if (! $inCountry) {
            return ['plausible' => false, 'penalty' => (float) $penalties['not_in_country']];
        }

        if ($regionId === null) {
            return ['plausible' => true, 'penalty' => 0.0];
        }

        $regionIds = DB::table('species_region_presence')
            ->join('regions', 'regions.id', '=', 'species_region_presence.region_id')
            ->where('species_region_presence.species_id', $speciesId)
            ->where('regions.country_id', $countryId)
            ->pluck('species_region_presence.region_id');

        if ($regionIds->isEmpty() || $regionIds->contains($regionId)) {
            return ['plausible' => true, 'penalty' => 0.0];
        }

        return ['plausible' => true, 'penalty' => (float) $penalties['not_in_region']];
    }
}
