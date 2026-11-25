<?php

namespace Tests\Feature;

use App\Domain\Identification\Exceptions\ProviderRateLimited;
use App\Domain\Identification\Exceptions\ProviderUnavailable;
use App\Domain\Identification\Jobs\RunIdentification;
use App\Domain\Identification\Models\IdentificationAttempt;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identification\Providers\FakeProvider;
use App\Domain\Identification\Services\IdentificationPipeline;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCapacityAndQuotaTest extends TestCase
{
    public function test_free_tier_requires_beta_consent(): void
    {
        $user = User::factory()->veteran()->create();

        $this->scan($user)->assertStatus(403)->assertJsonPath('code', 'ai_consent_required');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/ai-consent')->assertOk()->assertJsonPath('data.beta_ai_consent', true);
        $this->scan($user)->assertStatus(202);
    }

    public function test_daily_user_quota(): void
    {
        config(['fishdex.quotas.daily.free' => 2]);
        $user = $this->angler();

        $this->scan($user)->assertStatus(202);
        $this->scan($user)->assertStatus(202);
        $this->scan($user)->assertStatus(429)
            ->assertJsonPath('code', 'scan_quota_exceeded')
            ->assertJsonPath('quota.remaining', 0);
    }

    public function test_free_tier_daily_limit_parks_scans_until_reset_then_releases_them(): void
    {
        config(['fishdex.ai.free_tier.rpd' => 2, 'fishdex.ai.free_tier.usable_ratio' => 1.0]);
        $user = $this->angler();

        $this->scan($user)->assertStatus(202);
        $this->scan($user)->assertStatus(202);
        $third = $this->scan($user)->assertStatus(202)->json('data');

        $this->assertSame('waiting_capacity', $third['status']);
        $this->assertNotNull($third['expected_after']);
        $this->assertSame(60000, $third['poll_after_ms']);

        // Next quota day: counters reset → scheduler releases the parked scan.
        Cache::flush();
        $this->travelTo(now()->addDay()->addHour());
        $this->artisan('fishdex:release-waiting-scans')->assertSuccessful();

        $this->assertSame('completed', IdentificationRequest::query()->where('public_id', $third['scan_id'])->first()->status->value);
    }

    public function test_provider_429_parks_scan_instead_of_failing(): void
    {
        $user = $this->angler();
        FakeProvider::push(new ProviderRateLimited('quota', 429));

        $res = $this->scan($user)->json('data');

        $this->assertSame('waiting_capacity', $res['status']);
        $this->assertDatabaseHas('identification_attempts', ['status' => 'rate_limited']);
    }

    public function test_provider_outage_retries_then_marks_failed_without_charging_quota(): void
    {
        config(['fishdex.quotas.daily.free' => 1]);
        $user = $this->angler();
        Queue::fake();

        $scanId = $this->scan($user)->assertStatus(202)->json('data.scan_id');
        $scan = IdentificationRequest::query()->where('public_id', $scanId)->first();
        $job = new RunIdentification($scan->id);
        $this->assertSame(2, $job->tries, 'free tier: one retry only');

        // Each try hits an outage; the queue would retry, then call failed().
        foreach ([1, 2] as $try) {
            FakeProvider::push(new ProviderUnavailable('boom', 503));
            try {
                $job->handle(app(IdentificationPipeline::class));
                $this->fail('expected an exception so the queue retries');
            } catch (\RuntimeException) {
            }
        }
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame('failed', $scan->fresh()->status->value);
        $this->assertSame(2, IdentificationAttempt::query()->where('status', 'error')->count());

        // Failed scans don't use up the user's daily quota.
        $this->scan($user)->assertStatus(202);
    }

    public function test_kill_switch_blocks_new_scans(): void
    {
        app(SettingsService::class)->set('ai.kill_switch', true);

        $this->scan($this->angler())->assertStatus(503)->assertJsonPath('code', 'ai_temporarily_unavailable');
    }

    public function test_idempotency_key_is_required_and_replayed(): void
    {
        $user = $this->angler();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/scans', [])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

        $payload = ['image' => $this->photo(), 'client_uuid' => (string) Str::uuid(), 'captured_at' => now()->toIso8601String()];
        $first = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'k-1')->post('/api/v1/scans', $payload, ['Accept' => 'application/json']);
        $second = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'k-1')->post('/api/v1/scans', $payload, ['Accept' => 'application/json']);

        $this->assertSame($first->json('data.scan_id'), $second->json('data.scan_id'));
        $second->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_invalid_images_are_rejected(): void
    {
        $user = $this->angler();

        $this->scan($user, UploadedFile::fake()->createWithContent('x.jpg', 'not an image'))
            ->assertStatus(422)->assertJsonPath('code', 'image_rejected');

        $this->scan($user, $this->photo(null, 200, 150))
            ->assertStatus(422)->assertJsonPath('reason', 'too_small');
    }
}
