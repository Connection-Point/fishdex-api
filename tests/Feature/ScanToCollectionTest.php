<?php

namespace Tests\Feature;

use App\Domain\Admin\Models\Admin;
use App\Domain\Collection\Models\UserSpeciesCollection;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Identification\Models\IdentificationAttempt;
use App\Domain\Identification\Providers\FakeProvider;
use App\Domain\Review\Actions\ResolveReview;
use App\Domain\Review\Models\ReviewItem;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanToCollectionTest extends TestCase
{
    public function test_full_flow_high_confidence_confirm_collect_reveal(): void
    {
        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');
        $this->aiSays('Channa micropeltes (Cuvier, 1831)', 0.95, [['scientific_name' => 'Channa striata', 'confidence' => 0.2]]);

        $scan = $this->scan($user, null, ['lat' => 3.1, 'lng' => 101.6])->assertStatus(202);
        $scanId = $scan->json('data.scan_id');

        // Queue is sync in tests, so the scan is already processed.
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/scans/{$scanId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.outcome', 'identified')
            ->assertJsonPath('data.confidence_band', 'high')
            ->assertJsonPath('data.preselected_species_id', $toman->public_id)
            ->assertJsonPath('data.candidates.0.species.names.ms', 'Toman')
            ->assertJsonMissingPath('data.candidates.0.rarity_from_ai');

        $encounterId = $this->confirm($user, $scanId, 'species', $toman->public_id)
            ->assertCreated()
            ->assertJsonPath('data.encounter.identification_source', 'ai_confirmed')
            ->assertJsonPath('data.encounter.is_verified', true)
            ->assertJsonPath('data.collect_eligible', true)
            ->json('data.encounter.id');

        // Confirmed → seen, not yet collected.
        $this->assertSame('seen', UserSpeciesCollection::query()->where('user_id', $user->id)->first()->state->value);

        $this->decide($user, $encounterId, 'collect')
            ->assertOk()
            ->assertJsonPath('data.is_new_species', true)
            ->assertJsonPath('data.collection_entry.state', 'collected')
            ->assertJsonPath('data.reveal.rarity.code', 'rare')
            ->assertJsonPath('data.reveal.card.set', 'MY-S1');

        // Precise location is stored encrypted, not in plain text.
        $raw = \DB::table('fish_encounters')->where('public_id', $encounterId)->value('location_precise');
        $this->assertStringNotContainsString('101.6', $raw);
        $this->assertNotNull(\DB::table('fish_encounters')->where('public_id', $encounterId)->value('geohash5'));

        // Free tier: logged attempt with zero cost and the prompt version.
        $attempt = IdentificationAttempt::query()->first();
        $this->assertSame(0, (int) $attempt->cost_usd_micros);
        $this->assertSame('id-open-v1', $attempt->prompt_version);
    }

    public function test_catching_the_same_species_twice_creates_two_encounters_and_one_collection_row(): void
    {
        $user = $this->angler();
        $haruan = $this->species('Channa striata');

        foreach ([['collect', true], ['collect', false]] as [$decision, $expectNew]) {
            $this->aiSays('Channa striata', 0.9);
            $scanId = $this->scan($user)->json('data.scan_id');
            $encounterId = $this->confirm($user, $scanId, 'species', $haruan->public_id)->json('data.encounter.id');
            $this->decide($user, $encounterId, $decision)->assertOk()->assertJsonPath('data.is_new_species', $expectNew);
        }

        $this->assertSame(2, FishEncounter::query()->where('user_id', $user->id)->count());
        $rows = UserSpeciesCollection::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->collected_count);
        $this->assertSame(2, $rows[0]->encounter_count);
    }

    public function test_release_marks_species_seen_and_can_upgrade_to_collect_but_not_back(): void
    {
        $user = $this->angler();
        $kelah = $this->species('Tor tambroides');
        $this->aiSays('Tor tambroides', 0.9);

        $scanId = $this->scan($user)->json('data.scan_id');
        $encounterId = $this->confirm($user, $scanId, 'species', $kelah->public_id)->json('data.encounter.id');

        $this->decide($user, $encounterId, 'release')->assertOk()
            ->assertJsonPath('data.collection_entry.state', 'seen')
            ->assertJsonPath('data.is_new_species', false)
            ->assertJsonPath('data.reveal', null);

        $this->decide($user, $encounterId, 'collect')->assertOk()->assertJsonPath('data.is_new_species', true);
        $this->decide($user, $encounterId, 'release')->assertStatus(409)->assertJsonPath('code', 'decision_final');
    }

    public function test_medium_band_requires_choice_and_off_list_pick_is_user_selected_and_reviewed(): void
    {
        $user = $this->angler();
        $this->aiSays('Hampala macrolepidota', 0.55, [['scientific_name' => 'Barbonymus schwanenfeldii', 'confidence' => 0.45]]);

        $scan = $this->scan($user);
        $data = $this->actingAs($user, 'sanctum')->getJson('/api/v1/scans/'.$scan->json('data.scan_id'))->json('data');

        $this->assertContains($data['confidence_band'], ['medium', 'low']);
        $this->assertNull($data['preselected_species_id']);
        $this->assertCount(2, $data['candidates']);

        // User picks something the AI didn't suggest.
        $tilapia = $this->species('Oreochromis niloticus');
        $this->confirm($user, $data['scan_id'], 'species', $tilapia->public_id)
            ->assertCreated()
            ->assertJsonPath('data.encounter.identification_source', 'user_selected')
            ->assertJsonPath('data.encounter.is_verified', false)
            ->assertJsonPath('data.collect_eligible', true);

        $this->assertDatabaseHas('review_items', ['reason' => 'low_confidence_user_pick', 'status' => 'open']);
    }

    public function test_unsure_creates_unidentified_journal_entry_that_cannot_be_collected(): void
    {
        $user = $this->angler();
        FakeProvider::push(FakeProvider::result([['scientific_name' => 'Unknownus fishus', 'confidence' => 0.4]]));

        $scanId = $this->scan($user)->json('data.scan_id');
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/scans/{$scanId}")
            ->assertJsonPath('data.outcome', 'not_in_catalogue')
            ->assertJsonPath('data.confidence_band', 'low')
            ->assertJsonCount(0, 'data.candidates');

        $encounterId = $this->confirm($user, $scanId, 'unsure')->assertCreated()
            ->assertJsonPath('data.encounter.status', 'unidentified')->json('data.encounter.id');

        $this->decide($user, $encounterId, 'collect')->assertStatus(422)->assertJsonPath('code', 'not_collect_eligible');
        $this->assertSame(0, UserSpeciesCollection::count());
    }

    public function test_no_fish_outcome(): void
    {
        $user = $this->angler();
        FakeProvider::push(FakeProvider::result([], ['no_fish']));

        $scanId = $this->scan($user)->json('data.scan_id');
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/scans/{$scanId}")
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.outcome', 'no_fish');
    }

    public function test_screen_photo_flag_blocks_collection_and_goes_to_review(): void
    {
        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');
        $this->aiSays('Channa micropeltes', 0.95, [], ['screen_or_print']);

        $scanId = $this->scan($user)->json('data.scan_id');
        $this->confirm($user, $scanId, 'species', $toman->public_id)->assertJsonPath('data.collect_eligible', false);
        $this->assertDatabaseHas('review_items', ['reason' => 'possible_screen_photo']);
    }

    public function test_same_photo_from_another_user_is_flagged_duplicate(): void
    {
        $toman = $this->species('Channa micropeltes');
        [$a, $b] = [$this->angler(), $this->angler()];

        $this->aiSays('Channa micropeltes', 0.95);
        $this->scan($a, $this->photo(seed: 7));

        $this->aiSays('Channa micropeltes', 0.95);
        $scanId = $this->scan($b, $this->photo(seed: 7))->json('data.scan_id');

        $this->actingAs($b, 'sanctum')->getJson("/api/v1/scans/{$scanId}")->assertJsonPath('data.flags', ['duplicate_image']);
        $this->confirm($b, $scanId, 'species', $toman->public_id)->assertJsonPath('data.collect_eligible', false);
    }

    public function test_rescanning_own_photo_reuses_result_without_ai_call(): void
    {
        $user = $this->angler();
        $this->aiSays('Lates calcarifer', 0.9);
        $this->scan($user, $this->photo(seed: 11));
        $this->assertSame(['identify'], FakeProvider::$calls);

        $scanId = $this->scan($user, $this->photo(seed: 11))->json('data.scan_id');
        $this->assertSame(['identify'], FakeProvider::$calls, 'no second AI call');
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/scans/{$scanId}")
            ->assertJsonPath('data.candidates.0.species.scientific_name', 'Lates calcarifer');
    }

    public function test_scans_are_private_to_their_owner(): void
    {
        $owner = $this->angler();
        $other = $this->angler();
        $scanId = $this->scan($owner)->json('data.scan_id');

        $this->actingAs($other, 'sanctum')->getJson("/api/v1/scans/{$scanId}")->assertNotFound();
        $this->confirm($other, $scanId, 'unsure')->assertNotFound();
    }

    public function test_offline_replay_with_same_client_uuid_returns_original_scan(): void
    {
        $user = $this->angler();
        $uuid = (string) Str::uuid();

        $first = $this->scan($user, null, ['client_uuid' => $uuid])->json('data.scan_id');
        $second = $this->scan($user, null, ['client_uuid' => $uuid])->json('data.scan_id');

        $this->assertSame($first, $second);
    }

    public function test_manual_journal_entry_is_never_collectable(): void
    {
        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');

        $id = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/encounters', [
                'client_uuid' => (string) Str::uuid(),
                'species_id' => $toman->public_id,
                'caught_at' => now()->subDay()->toIso8601String(),
                'length_cm' => 62,
            ])->assertCreated()
            ->assertJsonPath('data.identification_source', 'manual')
            ->assertJsonPath('data.collect_eligible', false)
            ->json('data.id');

        $this->decide($user, $id, 'collect')->assertStatus(422);
        $this->assertSame('seen', UserSpeciesCollection::first()->state->value);
    }

    public function test_review_correction_moves_encounter_to_correct_species(): void
    {
        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');
        $haruan = $this->species('Channa striata');
        $this->aiSays('Channa micropeltes', 0.95);

        $scanId = $this->scan($user)->json('data.scan_id');
        $encounterId = $this->confirm($user, $scanId, 'species', $toman->public_id)->json('data.encounter.id');
        $this->decide($user, $encounterId, 'collect');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/encounters/{$encounterId}/dispute", ['comment' => 'I think this is haruan'])->assertStatus(202);

        $admin = Admin::create(['name' => 'Rev', 'email' => 'rev@fishdex.test', 'password' => 'x', 'role' => 'reviewer']);
        app(ResolveReview::class)->handle(ReviewItem::first(), 'corrected', $admin, $haruan);

        $this->assertNull(UserSpeciesCollection::query()->where('species_id', $toman->id)->first());
        $entry = UserSpeciesCollection::query()->where('species_id', $haruan->id)->first();
        $this->assertSame('collected', $entry->state->value);
        $this->assertSame('expert_corrected', FishEncounter::first()->identification_source->value);
    }

    public function test_collection_grid_shows_silhouettes_for_unseen_and_stats(): void
    {
        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');
        $this->aiSays('Channa micropeltes', 0.95);
        $scanId = $this->scan($user)->json('data.scan_id');
        $encounterId = $this->confirm($user, $scanId, 'species', $toman->public_id)->json('data.encounter.id');
        $this->decide($user, $encounterId, 'collect');

        $grid = $this->actingAs($user, 'sanctum')->getJson('/api/v1/collection')->assertOk();
        $this->assertSame(21, $grid->json('meta.total'));
        $this->assertSame(1, $grid->json('meta.collected'));

        $unseen = collect($grid->json('data'))->firstWhere('state', 'unseen');
        $this->assertArrayNotHasKey('names', $unseen['species'], 'unseen cards must not reveal the species');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('data.species_collected', 1)
            ->assertJsonPath('data.total_in_catalogue', 21)
            ->assertJsonPath('data.by_rarity.rare', 1);
    }
}
