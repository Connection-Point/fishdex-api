<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\TaxonFamily;
use App\Domain\Catalogue\Services\RarityResolver;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    public function test_search_finds_same_species_by_english_malay_alias_and_scientific_name(): void
    {
        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');

        foreach (['toman', 'Ikan Toman', 'giant snakehead', 'Channa micropeltes', 'toman bunga'] as $q) {
            $ids = $this->actingAs($user, 'sanctum')->getJson('/api/v1/species?q='.urlencode($q))
                ->assertOk()->json('data.*.id');
            $this->assertContains($toman->public_id, $ids, "query '{$q}' should find toman");
        }
    }

    public function test_species_detail_has_names_rarity_origin_and_card_but_no_internal_ids(): void
    {
        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');

        $res = $this->actingAs($user, 'sanctum')->withHeader('Accept-Language', 'ms')
            ->getJson("/api/v1/species/{$toman->public_id}")
            ->assertOk()
            ->assertJsonPath('data.names.ms', 'Toman')
            ->assertJsonPath('data.names.en', 'Giant snakehead')
            ->assertJsonPath('data.rarity.code', 'rare')
            ->assertJsonPath('data.rarity.name', 'Jarang')
            ->assertJsonPath('data.distribution.origin', 'native')
            ->assertJsonPath('data.user_state', 'unseen')
            ->assertJsonPath('data.card.set', 'MY-S1')
            ->assertJsonCount(3, 'data.habitats');

        $this->assertSame('Channa striata', $res->json('data.lookalikes.0.scientific_name'));
    }

    public function test_filters_and_snapshot_etag(): void
    {
        $user = $this->angler();

        $introduced = $this->actingAs($user, 'sanctum')->getJson('/api/v1/species?origin=introduced&limit=50')->json('data.*.scientific_name');
        $this->assertContains('Oreochromis niloticus', $introduced);
        $this->assertNotContains('Channa striata', $introduced);

        $marine = $this->actingAs($user, 'sanctum')->getJson('/api/v1/species?water_type=marine&limit=50')->json('data.*.scientific_name');
        $this->assertContains('Rastrelliger kanagurta', $marine);
        $this->assertNotContains('Channa striata', $marine);

        $legendary = $this->actingAs($user, 'sanctum')->getJson('/api/v1/species?rarity=legendary')->json('data.*.scientific_name');
        $this->assertSame(['Tor tambroides'], $legendary);

        $snap = $this->actingAs($user, 'sanctum')->getJson('/api/v1/catalogue/snapshot')->assertOk();
        $this->assertCount(21, $snap->json('data.species'));
        $etag = $snap->headers->get('ETag');

        $this->actingAs($user, 'sanctum')->withHeader('If-None-Match', $etag)->getJson('/api/v1/catalogue/snapshot')->assertStatus(304);
    }

    public function test_unpublished_species_are_hidden(): void
    {
        $user = $this->angler();
        $kelah = $this->species('Tor tambroides');
        $kelah->update(['is_published' => false]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/species/{$kelah->public_id}")->assertNotFound();
        $this->assertNotContains($kelah->public_id, $this->actingAs($user, 'sanctum')->getJson('/api/v1/species?q=kelah')->json('data.*.id'));
    }

    /** Regression: persistent cache stores (file/redis) must only receive plain arrays. */
    public function test_works_with_a_persistent_cache_store(): void
    {
        config(['cache.default' => 'file']);
        Cache::flush();
        $user = $this->angler();

        foreach ([1, 2] as $pass) { // second pass reads from the file cache
            app()->forgetInstance(RarityResolver::class);
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/species?q=toman')->assertOk()->assertJsonPath('data.0.rarity.code', 'rare');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/catalogue/snapshot')->assertOk();
        }

        Cache::flush();
    }

    public function test_families_list_only_families_with_published_species(): void
    {
        $user = $this->angler();
        TaxonFamily::create(['scientific_name' => 'Emptyidae']);

        $families = $this->actingAs($user, 'sanctum')->getJson('/api/v1/families')->assertOk()->json('data');

        $this->assertNotContains('Emptyidae', array_column($families, 'scientific_name'));
        $channidae = collect($families)->firstWhere('scientific_name', 'Channidae');
        $this->assertSame(3, $channidae['species_count']);
    }
}
