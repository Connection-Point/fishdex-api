<?php

namespace App\Domain\Shared\Enums;

enum IdentificationSource: string
{
    case AiConfirmed = 'ai_confirmed';
    case UserSelected = 'user_selected';
    case Manual = 'manual';
    case ExpertCorrected = 'expert_corrected';
}
