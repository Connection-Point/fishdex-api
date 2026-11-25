<?php

namespace App\Domain\Shared\Enums;

enum Decision: string
{
    case Undecided = 'undecided';
    case Collect = 'collect';
    case Release = 'release';
}
