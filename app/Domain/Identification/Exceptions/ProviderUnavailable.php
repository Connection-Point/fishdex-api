<?php

namespace App\Domain\Identification\Exceptions;

class ProviderUnavailable extends ProviderException
{
    public function __construct(string $message, ?int $httpStatus = null, int $latencyMs = 0, public readonly bool $isTimeout = false)
    {
        parent::__construct($message, $httpStatus, $latencyMs);
    }

    public function attemptStatus(): string
    {
        return $this->isTimeout ? 'timeout' : 'error';
    }
}
