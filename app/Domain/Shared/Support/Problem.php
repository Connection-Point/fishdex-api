<?php

namespace App\Domain\Shared\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Domain error rendered as RFC 9457 application/problem+json with a stable `code`.
 */
class Problem extends HttpException
{
    public function __construct(
        int $status,
        public readonly string $problemCode,
        string $detail,
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($status, $detail, null, $headers);
    }

    public static function make(int $status, string $code, string $detail, array $extra = [], array $headers = []): self
    {
        return new self($status, $code, $detail, $extra, $headers);
    }
}
