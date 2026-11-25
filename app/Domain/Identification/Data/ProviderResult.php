<?php

namespace App\Domain\Identification\Data;

final class ProviderResult
{
    /** Flags a provider may raise about the image. */
    public const FLAGS = ['no_fish', 'multiple_fish', 'screen_or_print', 'low_quality', 'not_a_photo'];

    /**
     * @param  list<RawCandidate>  $candidates
     * @param  list<string>  $flags
     */
    public function __construct(
        public readonly array $candidates,
        public readonly array $flags,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
        public readonly string $model,
        public readonly ?array $parsed = null,
    ) {}

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /** Parse the JSON object returned by our structured-output schema. */
    public static function fromStructured(array $json, int $inputTokens, int $outputTokens, int $latencyMs, string $model): self
    {
        $flags = array_values(array_intersect(self::FLAGS, array_map('strval', (array) ($json['flags'] ?? []))));

        $candidates = array_map(
            fn ($c) => RawCandidate::fromArray(is_array($c) ? $c : []),
            array_slice((array) ($json['candidates'] ?? []), 0, 5),
        );

        // Drop empty rows.
        $candidates = array_values(array_filter($candidates, fn (RawCandidate $c) => $c->scientificName || $c->commonName || $c->shortlistRef));

        return new self($candidates, $flags, $inputTokens, $outputTokens, $latencyMs, $model, $json);
    }
}
