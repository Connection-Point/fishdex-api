<?php

namespace App\Domain\Identification\Data;

final class RerankInput
{
    /**
     * @param  list<array{ref: string, scientific_name: string, common_names: list<string>, notes: ?string}>  $shortlist
     */
    public function __construct(
        public readonly IdentificationInput $base,
        public readonly array $shortlist,
    ) {}
}
