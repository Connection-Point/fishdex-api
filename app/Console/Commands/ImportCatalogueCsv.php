<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\Habitat;
use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Catalogue\Models\Region;
use App\Domain\Catalogue\Models\SpeciesName;
use App\Domain\Catalogue\Models\SpeciesSynonym;
use App\Domain\Catalogue\Models\TaxonFamily;
use App\Domain\Catalogue\Services\RarityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk catalogue import for the content team (M1). One row per species. Columns:
 *
 * scientific_name, authority, family, water_types (freshwater|brackish|marine, pipe-separated),
 * name_en, name_ms, aliases_ms (pipe), aliases_en (pipe), synonyms (pipe), origin, habitats (pipe codes),
 * regions (pipe ISO codes e.g. MY-01|MY-06), rarity (code), max_length_cm, identification_notes, publish (yes/no)
 *
 * Re-running updates existing species (matched by scientific name). Rarity changes create a new history row.
 */
class ImportCatalogueCsv extends Command
{
    protected $signature = 'fishdex:import-catalogue {file} {--country=MY} {--dry-run}';

    protected $description = 'Import or update species from a CSV file';

    public function handle(RarityResolver $rarity): int
    {
        $path = $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $country = Country::byIso($this->option('country'));
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim($h)), fgetcsv($handle) ?: []);
        $habitats = Habitat::query()->pluck('id', 'code');
        $regions = Region::query()->where('country_id', $country->id)->pluck('id', 'code');
        $tiers = RarityTier::query()->pluck('id', 'code');

        $line = 1;
        $ok = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($row)) === 0) {
                continue;
            }
            $r = array_combine($header, array_pad($row, count($header), ''));
            $split = fn ($v) => array_values(array_filter(array_map('trim', explode('|', (string) $v))));

            try {
                if (blank($r['scientific_name'] ?? null) || blank($r['family'] ?? null)) {
                    throw new \InvalidArgumentException('scientific_name and family are required');
                }
                $water = $split($r['water_types'] ?? '');
                if (array_diff($water, ['freshwater', 'brackish', 'marine']) || ! $water) {
                    throw new \InvalidArgumentException('water_types must be freshwater|brackish|marine');
                }
                if (filled($r['rarity'] ?? null) && ! isset($tiers[$r['rarity']])) {
                    throw new \InvalidArgumentException("unknown rarity {$r['rarity']}");
                }

                if ($this->option('dry-run')) {
                    $ok++;

                    continue;
                }

                DB::transaction(function () use ($r, $split, $water, $country, $habitats, $regions, $tiers, $rarity) {
                    $family = TaxonFamily::query()->firstOrCreate(['scientific_name' => trim($r['family'])]);

                    $species = FishSpecies::query()->withTrashed()->updateOrCreate(
                        ['scientific_name' => trim($r['scientific_name'])],
                        array_filter([
                            'authority' => $r['authority'] ?? null,
                            'family_id' => $family->id,
                            'water_types' => $water,
                            'max_length_cm' => is_numeric($r['max_length_cm'] ?? null) ? $r['max_length_cm'] : null,
                            'identification_notes' => $r['identification_notes'] ?? null,
                        ], fn ($v) => $v !== null && $v !== '') + [
                            'is_published' => strtolower($r['publish'] ?? '') === 'yes',
                        ],
                    );
                    if ($species->is_published && ! $species->published_at) {
                        $species->forceFill(['published_at' => now()])->save();
                    }

                    $species->names()->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $country->id))->delete();
                    if (filled($r['name_en'] ?? null)) {
                        SpeciesName::create(['species_id' => $species->id, 'name' => $r['name_en'], 'locale' => 'en', 'is_primary' => true]);
                    }
                    if (filled($r['name_ms'] ?? null)) {
                        SpeciesName::create(['species_id' => $species->id, 'name' => $r['name_ms'], 'locale' => 'ms', 'country_id' => $country->id, 'is_primary' => true]);
                    }
                    foreach ($split($r['aliases_ms'] ?? '') as $a) {
                        SpeciesName::create(['species_id' => $species->id, 'name' => $a, 'locale' => 'ms', 'country_id' => $country->id, 'name_type' => 'local_alias']);
                    }
                    foreach ($split($r['aliases_en'] ?? '') as $a) {
                        SpeciesName::create(['species_id' => $species->id, 'name' => $a, 'locale' => 'en', 'name_type' => 'local_alias']);
                    }
                    foreach ($split($r['synonyms'] ?? '') as $syn) {
                        SpeciesSynonym::query()->updateOrCreate(['scientific_name' => $syn], ['species_id' => $species->id]);
                    }

                    $species->habitats()->sync($habitats->only($split($r['habitats'] ?? ''))->values());

                    $regionIds = $regions->only($split($r['regions'] ?? ''))->values();
                    $species->regions()->wherePivotIn('region_id', $regions->values())->detach();
                    $species->regions()->attach($regionIds);

                    DB::table('species_country_status')->updateOrInsert(
                        ['species_id' => $species->id, 'country_id' => $country->id],
                        ['origin' => $r['origin'] ?: 'uncertain', 'is_present' => true, 'updated_at' => now(), 'created_at' => now()],
                    );

                    if (filled($r['rarity'] ?? null)) {
                        $current = $rarity->tierFor($species->id, $country->id);
                        if ($current?->code !== $r['rarity']) {
                            $rarity->assign($species->id, $country->id, $tiers[$r['rarity']], 'CSV import');
                        }
                    }
                });
                $ok++;
            } catch (Throwable $e) {
                $errors[] = "Line {$line}: ".$e->getMessage();
            }
        }
        fclose($handle);

        $this->info(($this->option('dry-run') ? '[dry run] ' : '')."{$ok} row(s) OK, ".count($errors).' error(s).');
        foreach ($errors as $error) {
            $this->warn($error);
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
