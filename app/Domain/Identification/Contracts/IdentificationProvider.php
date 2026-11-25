<?php

namespace App\Domain\Identification\Contracts;

use App\Domain\Identification\Data\IdentificationInput;
use App\Domain\Identification\Data\ProviderResult;
use App\Domain\Identification\Data\RerankInput;

/**
 * Provider-independent AI identification (D-08). Implementations must:
 *  - never return rarity or any game value,
 *  - throw ProviderRateLimited on 429/quota errors,
 *  - throw ProviderUnavailable on timeouts/5xx,
 *  - throw InvalidProviderOutput when the structured output can't be parsed.
 */
interface IdentificationProvider
{
    public function name(): string;

    public function model(): string;

    /** Pass 1: open-vocabulary identification. */
    public function identify(IdentificationInput $input): ProviderResult;

    /** Pass 2: choose among a shortlist of verified catalogue species. */
    public function rerank(RerankInput $input): ProviderResult;
}
