<?php

namespace App\Domain\Shared\Enums;

enum ScanOutcome: string
{
    case Identified = 'identified';
    case NoFish = 'no_fish';
    case MultipleFish = 'multiple_fish';
    case UnsupportedImage = 'unsupported_image';
    case NotInCatalogue = 'not_in_catalogue';
}
