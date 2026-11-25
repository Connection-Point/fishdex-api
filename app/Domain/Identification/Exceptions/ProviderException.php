<?php

namespace App\Domain\Identification\Exceptions;

use RuntimeException;

abstract class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly int $latencyMs = 0,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
        parent::__construct($message);
    }

    abstract public function attemptStatus(): string;
}
