<?php

namespace App\Domain\Identification\Data;

final class RawCandidate
{
    /**
     * @param  list<string>  $features
     */
    public function __construct(
        public readonly ?string $scientificName,
        public readonly ?string $commonName,
        public readonly float $confidence,
        public readonly array $features = [],
        public readonly ?string $shortlistRef = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $confidence = (float) ($data['confidence'] ?? 0);

        return new self(
            scientificName: self::clean($data['scientific_name'] ?? null),
            commonName: self::clean($data['common_name'] ?? null),
            confidence: max(0.0, min(1.0, $confidence > 1 ? $confidence / 100 : $confidence)),
            features: array_values(array_slice(array_filter(array_map('strval', (array) ($data['visible_features'] ?? []))), 0, 5)),
            shortlistRef: self::clean($data['ref'] ?? null),
        );
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' || $value === null ? null : mb_substr($value, 0, 160);
    }
}
