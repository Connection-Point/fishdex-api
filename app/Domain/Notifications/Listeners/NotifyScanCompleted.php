<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Identification\Events\ScanCompleted;
use App\Domain\Notifications\PushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyScanCompleted implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly PushNotifier $push) {}

    public function handle(ScanCompleted $event): void
    {
        $scan = $event->scan->loadMissing('user');

        $this->push->sendData($scan->user, [
            'type' => 'scan_completed',
            'scan_id' => $scan->public_id,
            'status' => $scan->status->value,
        ]);
    }
}
