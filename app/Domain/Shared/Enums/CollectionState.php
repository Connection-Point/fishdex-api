<?php

namespace App\Domain\Shared\Enums;

enum CollectionState: string
{
    case Seen = 'seen';
    case Collected = 'collected';
}
