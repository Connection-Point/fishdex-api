<?php

namespace Database\Seeders;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\Habitat;
use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Catalogue\Models\Region;
use Illuminate\Database\Seeder;

/** Countries, Malaysian states (ISO 3166-2:MY), habitats and rarity tiers. Safe to re-run. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['MY', 'Malaysia', 'ms', true, 1],
            ['SG', 'Singapore', 'en', false, 2],
            ['ID', 'Indonesia', 'id', false, 3],
            ['TH', 'Thailand', 'th', false, 4],
        ];
        foreach ($countries as [$iso, $name, $locale, $launched, $order]) {
            Country::query()->updateOrCreate(['iso2' => $iso], ['name' => $name, 'default_locale' => $locale, 'is_launched' => $launched, 'sort_order' => $order]);
        }

        $my = Country::byIso('MY');
        $states = [
            ['MY-01', 'Johor', 'state'], ['MY-02', 'Kedah', 'state'], ['MY-03', 'Kelantan', 'state'],
            ['MY-04', 'Melaka', 'state'], ['MY-05', 'Negeri Sembilan', 'state'], ['MY-06', 'Pahang', 'state'],
            ['MY-07', 'Pulau Pinang', 'state'], ['MY-08', 'Perak', 'state'], ['MY-09', 'Perlis', 'state'],
            ['MY-10', 'Selangor', 'state'], ['MY-11', 'Terengganu', 'state'], ['MY-12', 'Sabah', 'state'],
            ['MY-13', 'Sarawak', 'state'], ['MY-14', 'W.P. Kuala Lumpur', 'federal_territory'],
            ['MY-15', 'W.P. Labuan', 'federal_territory'], ['MY-16', 'W.P. Putrajaya', 'federal_territory'],
        ];
        foreach ($states as [$code, $name, $type]) {
            Region::query()->updateOrCreate(['country_id' => $my->id, 'code' => $code], ['name' => $name, 'type' => $type]);
        }

        $habitats = [
            ['river', 'River & stream', 'Sungai & anak sungai'],
            ['lake_reservoir', 'Lake & reservoir', 'Tasik & empangan'],
            ['paddy_canal', 'Paddy field & canal', 'Sawah & parit'],
            ['swamp_peat', 'Swamp & peat forest', 'Paya & hutan gambut'],
            ['mangrove_estuary', 'Mangrove & estuary', 'Bakau & muara'],
            ['coastal_reef', 'Coast & reef', 'Pantai & terumbu'],
            ['open_sea', 'Open sea', 'Laut terbuka'],
            ['pond_kolam', 'Pond & fishing pond', 'Kolam & kolam pancing'],
        ];
        foreach ($habitats as [$code, $en, $ms]) {
            Habitat::query()->updateOrCreate(['code' => $code], ['name_en' => $en, 'name_ms' => $ms]);
        }

        // Final colours come from the designer; these are placeholders consistent with the docs.
        $tiers = [
            ['common', 1, 'Common', 'Biasa', '#9AA5B1', 'none', 'standard'],
            ['uncommon', 2, 'Uncommon', 'Luar Biasa', '#2FBF71', 'sheen', 'standard'],
            ['rare', 3, 'Rare', 'Jarang', '#2D7FF9', 'holo', 'rare'],
            ['epic', 4, 'Epic', 'Epik', '#9B5DE5', 'prism', 'epic'],
            ['legendary', 5, 'Legendary', 'Legenda', '#F5B700', 'aurora', 'legendary'],
        ];
        foreach ($tiers as [$code, $order, $en, $ms, $color, $foil, $anim]) {
            RarityTier::query()->updateOrCreate(['code' => $code], [
                'sort_order' => $order, 'name_en' => $en, 'name_ms' => $ms, 'color_hex' => $color,
                'foil_effect' => $foil, 'reveal_animation' => $anim,
            ]);
        }
    }
}
