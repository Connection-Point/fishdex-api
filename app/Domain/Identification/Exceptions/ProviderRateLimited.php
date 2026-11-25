<?php

namespace App\Domain\Identification\Exceptions;

/** 429 / RESOURCE_EXHAUSTED: the provider quota is used up. Never retried immediately. */
class ProviderRateLimited extends ProviderException
{
    public function attemptStatus(): string
    {
        return 'rate_limited';
    }
}
