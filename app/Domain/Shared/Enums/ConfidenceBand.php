<?php

namespace App\Domain\Shared\Enums;

enum ConfidenceBand: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
