<?php

namespace Database\Seeders;

use App\Domain\Cards\Models\CardSet;
use App\Domain\Cards\Models\FishCard;
use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\Habitat;
use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Catalogue\Models\SpeciesName;
use App\Domain\Catalogue\Models\TaxonFamily;
use App\Domain\Catalogue\Services\RarityResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DEVELOPMENT SAMPLE ONLY — 20 well-known Malaysian angling species so the app and
 * AI pipeline can be exercised end-to-end. Curators must verify every record
 * (names, origin, distribution, identification notes) before production.
 * Conservation status is intentionally NOT seeded: it must come from a cited source.
 */
class MalaysiaSampleCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $my = Country::byIso('MY');
        $tiers = RarityTier::all()->keyBy('code');
        $habitats = Habitat::all()->keyBy('code');

        $families = [
            'Channidae' => ['Snakeheads', 'Haruan / toman'],
            'Cyprinidae' => ['Carps, barbs and minnows', 'Kap & lampam'],
            'Pangasiidae' => ['Shark catfishes', 'Patin'],
            'Bagridae' => ['Bagrid catfishes', 'Baung'],
            'Cichlidae' => ['Cichlids', 'Tilapia & bass'],
            'Clariidae' => ['Airbreathing catfishes', 'Keli'],
            'Latidae' => ['Lates perches', 'Siakap'],
            'Serranidae' => ['Groupers and sea basses', 'Kerapu'],
            'Lutjanidae' => ['Snappers', 'Jenahak / ikan merah'],
            'Scombridae' => ['Mackerels and tunas', 'Kembung & tenggiri'],
            'Anabantidae' => ['Climbing gouramies', 'Puyu'],
            'Osphronemidae' => ['Gouramies', 'Sepat & kaloi'],
            'Synbranchidae' => ['Swamp eels', 'Belut'],
            'Butidae' => ['Sleeper gobies', 'Ketutu'],
        ];
        foreach ($families as $sci => [$en, $ms]) {
            TaxonFamily::query()->updateOrCreate(['scientific_name' => $sci], ['common_name_en' => $en, 'common_name_ms' => $ms]);
        }
        $familyIds = TaxonFamily::query()->pluck('id', 'scientific_name');

        // [scientific, family, water types, origin, rarity, habitats, en name, ms name, aliases, notes]
        $species = [
            ['Channa micropeltes', 'Channidae', ['freshwater'], 'native', 'rare', ['river', 'lake_reservoir', 'swamp_peat'], 'Giant snakehead', 'Toman', ['Toman bunga'],
                'Large snakehead. Adults have a broad dark lateral band from eye to tail; juveniles are striped red-orange and black (often sold as "red snakehead"). Large mouth with strong teeth.'],
            ['Channa striata', 'Channidae', ['freshwater'], 'native', 'common', ['paddy_canal', 'swamp_peat', 'river', 'pond_kolam'], 'Striped snakehead', 'Haruan', ['Aruan'],
                'Cylindrical body, dark chevron-like bars along the flank, pale belly. Smaller than toman and lacks the single broad dark band of adult toman.'],
            ['Channa lucius', 'Channidae', ['freshwater'], 'native', 'uncommon', ['swamp_peat', 'river'], 'Forest snakehead', 'Bujuk', ['Splendid snakehead'],
                'Slender snakehead with a pointed, pike-like head and rows of dark blotches along the side.'],
            ['Tor tambroides', 'Cyprinidae', ['freshwater'], 'native', 'legendary', ['river'], 'Malaysian mahseer', 'Kelah', ['Kelah merah', 'Empurau'],
                'Large, heavily scaled cyprinid of clear forest rivers. Fleshy median lobe on the lower lip; fins often reddish.'],
            ['Hampala macrolepidota', 'Cyprinidae', ['freshwater'], 'native', 'uncommon', ['river', 'lake_reservoir'], 'Hampala barb', 'Sebarau', [],
                'Silvery barb with a distinct dark vertical bar below the dorsal fin and red-tinged tail lobes with dark margins.'],
            ['Barbonymus schwanenfeldii', 'Cyprinidae', ['freshwater'], 'native', 'common', ['river', 'lake_reservoir'], 'Tinfoil barb', 'Lampam sungai', ['Lampam'],
                'Deep-bodied silvery barb with bright red/orange fins and a black margin on the dorsal fin and tail lobes.'],
            ['Pangasianodon hypophthalmus', 'Pangasiidae', ['freshwater'], 'introduced', 'uncommon', ['pond_kolam', 'river', 'lake_reservoir'], 'Iridescent shark catfish', 'Patin', ['Patin hitam'],
                'Shark-like catfish, grey-silver with dark fins, small barbels. Common in commercial fishing ponds.'],
            ['Hemibagrus nemurus', 'Bagridae', ['freshwater'], 'native', 'common', ['river', 'lake_reservoir'], 'Asian redtail catfish (baung)', 'Baung', [],
                'Bagrid catfish with long maxillary barbels, adipose fin and forked tail; grey-brown body.'],
            ['Oreochromis niloticus', 'Cichlidae', ['freshwater', 'brackish'], 'introduced', 'common', ['pond_kolam', 'lake_reservoir', 'river'], 'Nile tilapia', 'Tilapia', ['Tilapia hitam'],
                'Deep-bodied cichlid with regular vertical stripes on the tail fin.'],
            ['Clarias gariepinus', 'Clariidae', ['freshwater'], 'introduced', 'common', ['pond_kolam', 'river', 'swamp_peat'], 'African sharptooth catfish', 'Keli Afrika', [],
                'Long-bodied airbreathing catfish with a flattened bony head, four pairs of barbels and long dorsal and anal fins.'],
            ['Lates calcarifer', 'Latidae', ['freshwater', 'brackish', 'marine'], 'native', 'uncommon', ['mangrove_estuary', 'coastal_reef', 'pond_kolam'], 'Barramundi', 'Siakap', [],
                'Silver perch-like fish with a concave head profile, large jaw reaching behind the eye and a rounded tail.'],
            ['Epinephelus coioides', 'Serranidae', ['marine', 'brackish'], 'native', 'rare', ['coastal_reef', 'mangrove_estuary'], 'Orange-spotted grouper', 'Kerapu', ['Kerapu lumpur'],
                'Grouper with numerous small orange-brown spots and faint dark bars. "Kerapu" covers many grouper species — check spot pattern.'],
            ['Lutjanus argentimaculatus', 'Lutjanidae', ['marine', 'brackish', 'freshwater'], 'native', 'rare', ['mangrove_estuary', 'coastal_reef'], 'Mangrove red snapper', 'Jenahak', [],
                'Copper-red to greenish-bronze snapper; juveniles have pale bars and a blue line below the eye.'],
            ['Rastrelliger kanagurta', 'Scombridae', ['marine'], 'native', 'common', ['open_sea', 'coastal_reef'], 'Indian mackerel', 'Kembung', [],
                'Small mackerel with dark spots along the dorsal fin base and golden stripes on the upper flank.'],
            ['Scomberomorus commerson', 'Scombridae', ['marine'], 'native', 'uncommon', ['open_sea', 'coastal_reef'], 'Narrow-barred Spanish mackerel', 'Tenggiri', ['Tenggiri batang'],
                'Long, silver, torpedo-shaped fish with many thin, wavy vertical bars on the flank.'],
            ['Anabas testudineus', 'Anabantidae', ['freshwater', 'brackish'], 'native', 'common', ['paddy_canal', 'swamp_peat', 'pond_kolam'], 'Climbing perch', 'Puyu', ['Betok'],
                'Small, robust fish with spiny gill covers, a dark spot at the tail base and another behind the gill cover.'],
            ['Trichopodus pectoralis', 'Osphronemidae', ['freshwater'], 'introduced', 'common', ['paddy_canal', 'swamp_peat', 'pond_kolam'], 'Snakeskin gourami', 'Sepat siam', [],
                'Laterally compressed gourami with thread-like pelvic fins and irregular dark oblique bars (snakeskin pattern).'],
            ['Monopterus albus', 'Synbranchidae', ['freshwater'], 'native', 'uncommon', ['paddy_canal', 'swamp_peat'], 'Asian swamp eel', 'Belut', [],
                'Eel-like, scaleless body with no pectoral or pelvic fins; a single gill slit under the head.'],
            ['Oxyeleotris marmorata', 'Butidae', ['freshwater'], 'native', 'uncommon', ['river', 'swamp_peat', 'lake_reservoir'], 'Marble goby', 'Ketutu', ['Ikan hantu', 'Ubi'],
                'Large sleeper goby with a broad flat head, protruding lower jaw and brown marbled pattern.'],
            ['Osteochilus vittatus', 'Cyprinidae', ['freshwater'], 'native', 'common', ['river', 'paddy_canal'], 'Bonylip barb', 'Terbul', ['Rong'],
                'Small barb with a dark mid-lateral stripe and thickened, fringed lips.'],
        ];

        // Added after Series 1 was numbered: appended in this order so existing card numbers never shift.
        $additions = [
            ['Cichla ocellaris', 'Cichlidae', ['freshwater'], 'introduced', 'rare', ['lake_reservoir', 'pond_kolam', 'river'], 'Peacock bass', 'Peacock bass', ['Butterfly peacock bass'],
                'Elongated predatory cichlid with a large mouth, three dark vertical bars, reddish lower fins and a gold-ringed black eye-spot at the tail base. Introduced into lakes and old mining pools.'],
        ];
        $species = array_merge($species, $additions);

        $resolver = app(RarityResolver::class);

        DB::transaction(function () use ($species, $additions, $familyIds, $my, $tiers, $habitats, $resolver) {
            foreach ($species as [$sci, $family, $water, $origin, $rarity, $habitatCodes, $en, $ms, $aliases, $notes]) {
                $s = FishSpecies::query()->updateOrCreate(['scientific_name' => $sci], [
                    'family_id' => $familyIds[$family],
                    'water_types' => $water,
                    'identification_notes' => $notes,
                    'taxonomy_status' => 'provisional', // curator must verify
                    'is_published' => true,
                    'published_at' => now(),
                ]);

                $s->names()->delete();
                SpeciesName::create(['species_id' => $s->id, 'name' => $en, 'locale' => 'en', 'name_type' => 'common', 'is_primary' => true]);
                SpeciesName::create(['species_id' => $s->id, 'name' => $ms, 'locale' => 'ms', 'country_id' => $my->id, 'name_type' => 'common', 'is_primary' => true]);
                foreach ($aliases as $alias) {
                    SpeciesName::create(['species_id' => $s->id, 'name' => $alias, 'locale' => 'ms', 'country_id' => $my->id, 'name_type' => 'local_alias']);
                }

                $s->habitats()->sync($habitats->whereIn('code', $habitatCodes)->pluck('id')); // not only(): on Eloquent collections it filters by primary key

                DB::table('species_country_status')->updateOrInsert(
                    ['species_id' => $s->id, 'country_id' => $my->id],
                    ['origin' => $origin, 'is_present' => true, 'legal_protection' => 'none', 'created_at' => now(), 'updated_at' => now()],
                );

                if (! $s->rarities()->where('country_id', $my->id)->exists()) {
                    $resolver->assign($s->id, $my->id, $tiers[$rarity]->id, 'Development sample — editorial placeholder');
                }
            }

            // "Kerapu" and "Baung" are generic market names for many species.
            SpeciesName::query()->whereIn('normalized_name', ['kerapu', 'baung'])->update(['is_ambiguous' => true]);

            // Look-alikes guide pass-2 shortlists and the "often confused with" hint.
            $ids = FishSpecies::query()->pluck('id', 'scientific_name');
            $pairs = [['Channa micropeltes', 'Channa striata'], ['Hampala macrolepidota', 'Barbonymus schwanenfeldii'], ['Pangasianodon hypophthalmus', 'Hemibagrus nemurus']];
            foreach ($pairs as [$a, $b]) {
                FishSpecies::query()->whereKey($ids[$a])->update(['lookalike_species_ids' => json_encode([$ids[$b]])]);
                FishSpecies::query()->whereKey($ids[$b])->update(['lookalike_species_ids' => json_encode([$ids[$a]])]);
            }

            // Launch card set: the original 20 numbered by family, then scientific name; additions appended.
            $set = CardSet::query()->updateOrCreate(['code' => config('fishdex.catalogue.default_card_set')], [
                'name' => 'Malaysia — Series 1', 'country_id' => $my->id, 'theme' => 'Rivers to Reefs', 'is_active' => true,
            ]);

            $core = array_diff(array_column($species, 0), array_column($additions, 0));
            $ordered = FishSpecies::query()->whereIn('fish_species.scientific_name', $core)
                ->join('taxon_families', 'taxon_families.id', '=', 'fish_species.family_id')
                ->orderBy('taxon_families.scientific_name')->orderBy('fish_species.scientific_name')
                ->pluck('fish_species.id');
            foreach (array_column($additions, 0) as $sci) {
                $ordered->push($ids[$sci] ?? FishSpecies::query()->where('scientific_name', $sci)->value('id'));
            }

            foreach ($ordered->values() as $i => $speciesId) {
                FishCard::query()->updateOrCreate(
                    ['card_set_id' => $set->id, 'species_id' => $speciesId, 'variant' => 'standard'],
                    ['card_number' => str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'is_active' => true],
                );
            }
            $set->forceFill(['total_cards' => $ordered->count()])->save();
        });
    }
}
