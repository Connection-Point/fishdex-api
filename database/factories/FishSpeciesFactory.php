<?php

namespace Database\Factories;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\TaxonFamily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FishSpecies> */
class FishSpeciesFactory extends Factory
{
    protected $model = FishSpecies::class;

    public function definition(): array
    {
        $genus = ucfirst(Str::lower(Str::random(7)));

        return [
            'scientific_name' => $genus.' '.Str::lower(Str::random(9)),
            'family_id' => fn () => TaxonFamily::query()->firstOrCreate(['scientific_name' => 'Testidae'])->id,
            'water_types' => ['freshwater'],
            'is_published' => true,
            'published_at' => now(),
        ];
    }
}
