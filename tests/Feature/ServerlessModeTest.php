<?php

namespace Tests\Feature;

use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Shared\Enums\ScanStatus;
use Tests\TestCase;

class ServerlessModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['fishdex.runtime.inline_scans' => true, 'fishdex.runtime.cron_secret' => 'test-secret']);
    }

    public function test_upload_only_queues_and_the_status_poll_runs_the_ai(): void
    {
        $user = $this->angler();
        $this->aiSays('Channa micropeltes', 0.95);

        $scanId = $this->scan($user)->assertStatus(202)->json('data.scan_id');
        $this->assertSame('queued', IdentificationRequest::query()->sole()->status->value, 'no AI call during upload');

        $poll = $this->actingAs($user, 'sanctum')->getJson("/api/v1/scans/{$scanId}")->assertOk();
        $this->assertNotSame('queued', $poll->json('data.status'));
        $this->assertTrue(IdentificationRequest::query()->sole()->status->isTerminal());
    }

    public function test_abandoned_processing_scan_is_retried_on_poll(): void
    {
        $user = $this->angler();
        $this->aiSays('Channa striata', 0.95);
        $scanId = $this->scan($user)->json('data.scan_id');

        $scan = IdentificationRequest::query()->sole();
        $scan->forceFill(['status' => ScanStatus::Processing, 'attempt_count' => 1])->save();
        IdentificationRequest::query()->whereKey($scan->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/scans/{$scanId}")->assertOk();
        $this->assertTrue($scan->refresh()->status->isTerminal());
    }

    public function test_daily_cron_requires_the_secret_and_times_out_abandoned_scans(): void
    {
        $user = $this->angler();
        $this->scan($user);
        IdentificationRequest::query()->update(['updated_at' => now()->subHours(2)]);

        $this->get('/internal/cron/daily')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer wrong')->get('/internal/cron/daily')->assertNotFound();

        $this->withHeader('Authorization', 'Bearer test-secret')->get('/internal/cron/daily')
            ->assertOk()
            ->assertJsonPath('ran.fishdex:sweep-stuck-scans', 'ok');

        $this->assertSame(ScanStatus::TimedOut, IdentificationRequest::query()->sole()->status);
    }
}
