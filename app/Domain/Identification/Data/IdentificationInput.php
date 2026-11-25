<?php

namespace App\Domain\Identification\Data;

/** Only the image and coarse context are sent to the AI — never user data or precise GPS. */
final class IdentificationInput
{
    public function __construct(
        public readonly string $imageBytes,
        public readonly string $mime,
        public readonly string $countryName,
        public readonly ?string $regionName = null,
        public readonly string $encounterType = 'catch',
    ) {}
}
