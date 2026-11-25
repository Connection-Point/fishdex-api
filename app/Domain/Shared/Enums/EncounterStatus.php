<?php

namespace App\Domain\Shared\Enums;

enum EncounterStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Unidentified = 'unidentified';
    case Rejected = 'rejected';
}
