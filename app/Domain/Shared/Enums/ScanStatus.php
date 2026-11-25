<?php

namespace App\Domain\Shared\Enums;

enum ScanStatus: string
{
    case Queued = 'queued';
    case WaitingCapacity = 'waiting_capacity';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::TimedOut, self::Rejected], true);
    }
}
