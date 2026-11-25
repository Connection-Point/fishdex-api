<?php

namespace App\Domain\Collection\Listeners;

use App\Domain\Collection\Services\StatsCalculator;
use App\Domain\Encounter\Events\EncounterChanged;
use App\Domain\Encounter\Events\EncounterDecided;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Stats are recomputed off the request path. */
class RefreshUserStats implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly StatsCalculator $stats) {}

    public function handle(EncounterDecided|EncounterChanged $event): void
    {
        $userId = $event instanceof EncounterDecided ? $event->encounter->user_id : $event->userId;

        if ($user = User::query()->find($userId)) {
            $this->stats->recalculate($user);
        }
    }
}
