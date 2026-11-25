<?php

namespace App\Console\Commands;

use App\Domain\Identification\Jobs\RunIdentification;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identification\Services\AiBudgetGuard;
use App\Domain\Identification\Services\AiCapacity;
use App\Domain\Shared\Enums\ScanStatus;
use Illuminate\Console\Command;

/** Re-queues scans that waited for AI capacity (free-tier daily reset, cooldown, kill-switch). FIFO. */
class ReleaseWaitingScans extends Command
{
    protected $signature = 'fishdex:release-waiting-scans';

    protected $description = 'Dispatch scans in waiting_capacity once AI capacity is available again';

    public function handle(AiCapacity $capacity, AiBudgetGuard $budget): int
    {
        if (config('fishdex.runtime.inline_scans')) {
            return self::SUCCESS; // waiting scans are retried by the app's status poll
        }

        if (! $budget->allowsNewScans()) {
            return self::SUCCESS;
        }

        $room = min($capacity->perMinuteLimit(), $capacity->remainingToday() ?? PHP_INT_MAX);
        if ($room <= 0) {
            return self::SUCCESS;
        }

        $scans = IdentificationRequest::query()
            ->where('status', ScanStatus::WaitingCapacity)
            ->where(fn ($q) => $q->whereNull('expected_after')->orWhere('expected_after', '<=', now()))
            ->orderBy('queued_at')
            ->limit($room)
            ->get();

        foreach ($scans as $scan) {
            $scan->forceFill(['status' => ScanStatus::Queued, 'expected_after' => null])->save();
            RunIdentification::dispatch($scan->id);
        }

        $this->info("Released {$scans->count()} scan(s).");

        return self::SUCCESS;
    }
}
