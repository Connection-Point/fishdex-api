<?php

namespace App\Console\Commands;

use App\Domain\Identification\Jobs\RunIdentification;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Shared\Enums\ScanStatus;
use Illuminate\Console\Command;

/** Scans stuck in processing (worker died) are re-queued once, then timed out. */
class SweepStuckScans extends Command
{
    protected $signature = 'fishdex:sweep-stuck-scans';

    protected $description = 'Recover or time out scans stuck in processing/queued';

    public function handle(): int
    {
        if (config('fishdex.runtime.inline_scans')) {
            // No worker: scans only run while the app polls. Time out ones nobody polled for an hour.
            $n = IdentificationRequest::query()
                ->whereIn('status', [ScanStatus::Processing, ScanStatus::Queued, ScanStatus::WaitingCapacity])
                ->where('updated_at', '<', now()->subHour())
                ->update(['status' => ScanStatus::TimedOut, 'error_code' => 'timed_out', 'completed_at' => now(), 'updated_at' => now()]);
            $this->info("Timed out {$n} abandoned scan(s).");

            return self::SUCCESS;
        }

        $stuck = IdentificationRequest::query()
            ->whereIn('status', [ScanStatus::Processing, ScanStatus::Queued])
            ->where('updated_at', '<', now()->subMinutes(2))
            ->limit(200)
            ->get();

        foreach ($stuck as $scan) {
            if ($scan->attempt_count >= 2) {
                $scan->forceFill(['status' => ScanStatus::TimedOut, 'error_code' => 'timed_out', 'completed_at' => now()])->save();
            } else {
                $scan->forceFill(['status' => ScanStatus::Queued])->save();
                RunIdentification::dispatch($scan->id);
            }
        }

        return self::SUCCESS;
    }
}
