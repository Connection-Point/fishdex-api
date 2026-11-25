<?php

namespace App\Domain\Identification\Events;

use App\Domain\Identification\Models\IdentificationRequest;
use Illuminate\Foundation\Events\Dispatchable;

class ScanCompleted
{
    use Dispatchable;

    public function __construct(public readonly IdentificationRequest $scan) {}
}
