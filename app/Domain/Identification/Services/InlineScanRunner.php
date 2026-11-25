<?php

namespace App\Domain\Identification\Services;

use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Shared\Enums\ScanStatus;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Serverless hosting (e.g. Vercel) has no queue worker. With `fishdex.runtime.inline_scans` on,
 * uploads only record the scan, and the app's status poll (GET /scans/{id}) runs the AI step
 * inside that request. A cache lock makes sure only one poll works on a scan at a time.
 */
class InlineScanRunner
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(private readonly IdentificationPipeline $pipeline) {}

    public function enabled(): bool
    {
        return (bool) config('fishdex.runtime.inline_scans');
    }

    public function advance(IdentificationRequest $scan): IdentificationRequest
    {
        if (! $this->enabled() || ! $this->isDue($scan)) {
            return $scan;
        }

        $lock = Cache::lock('scan-inline:'.$scan->id, 150);
        if (! $lock->get()) {
            return $scan; // another poll is already running it
        }

        try {
            $scan->refresh();
            if (! $this->isDue($scan)) {
                return $scan;
            }

            if ($scan->status === ScanStatus::Processing) {
                // A previous request died mid-run (function timeout / instance recycled).
                if ($scan->attempt_count >= self::MAX_ATTEMPTS) {
                    $scan->forceFill(['status' => ScanStatus::TimedOut, 'error_code' => 'timed_out', 'completed_at' => now()])->save();

                    return $scan;
                }
                $scan->forceFill(['status' => ScanStatus::Queued])->save();
            }

            try {
                $this->pipeline->run($scan);
            } catch (Throwable $e) {
                report($e);
                $scan->refresh();
                if (! $scan->status->isTerminal()) {
                    if ($scan->attempt_count >= self::MAX_ATTEMPTS) {
                        $this->pipeline->markFailed($scan, 'ai_failed');
                    } else {
                        $scan->forceFill(['status' => ScanStatus::Queued])->save(); // retried on the next poll
                    }
                }
            }
        } finally {
            $lock->release();
        }

        return $scan->refresh();
    }

    private function isDue(IdentificationRequest $scan): bool
    {
        return match ($scan->status) {
            ScanStatus::Queued => true,
            ScanStatus::WaitingCapacity => $scan->expected_after === null || $scan->expected_after->isPast(),
            ScanStatus::Processing => $scan->updated_at?->lt(now()->subMinutes(2)) ?? false,
            default => false,
        };
    }
}
