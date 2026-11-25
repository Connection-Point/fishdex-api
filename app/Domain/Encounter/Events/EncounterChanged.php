<?php

namespace App\Domain\Encounter\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Any change that can affect a user's stats (edit, delete, correction). */
class EncounterChanged
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
