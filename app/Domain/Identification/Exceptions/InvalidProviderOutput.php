<?php

namespace App\Domain\Identification\Exceptions;

class InvalidProviderOutput extends ProviderException
{
    public function attemptStatus(): string
    {
        return 'invalid_output';
    }
}
