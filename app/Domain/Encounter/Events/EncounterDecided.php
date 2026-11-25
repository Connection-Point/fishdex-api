<?php

namespace App\Domain\Encounter\Events;

use App\Domain\Encounter\Models\FishEncounter;
use Illuminate\Foundation\Events\Dispatchable;

class EncounterDecided
{
    use Dispatchable;

    public function __construct(public readonly FishEncounter $encounter, public readonly bool $isNewSpecies) {}
}
