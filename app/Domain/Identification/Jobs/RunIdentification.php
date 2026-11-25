<?php

namespace App\Domain\Identification\Jobs;

use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identification\Services\IdentificationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunIdentification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries;

    public function __construct(public readonly int $scanId)
    {
        $this->onQueue('scans');
        // Free tier: 1 retry max so retries don't burn the shared daily quota (D-16).
        $this->tries = config('fishdex.ai.tier') === 'free' ? 1 + (int) config('fishdex.ai.free_tier.max_retries', 1) : 3;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(IdentificationPipeline $pipeline): void
    {
        $scan = IdentificationRequest::query()->find($this->scanId);
        if ($scan) {
            $pipeline->run($scan);
        }
    }

    public function failed(?Throwable $e): void
    {
        $scan = IdentificationRequest::query()->find($this->scanId);
        if ($scan && ! $scan->status->isTerminal()) {
            app(IdentificationPipeline::class)->markFailed($scan, 'ai_failed');
        }
    }
}
