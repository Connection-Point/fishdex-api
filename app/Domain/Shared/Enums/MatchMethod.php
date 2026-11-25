<?php

namespace App\Domain\Shared\Enums;

enum MatchMethod: string
{
    case ScientificExact = 'scientific_exact';
    case Synonym = 'synonym';
    case CommonName = 'common_name';
    case Fuzzy = 'fuzzy';
    case ConstrainedPick = 'constrained_pick';
    case None = 'none';

    public function baseScore(): float
    {
        return match ($this) {
            self::ScientificExact => 1.0,
            self::Synonym => 0.95,
            self::ConstrainedPick => 0.9,
            self::CommonName => 0.8,
            self::Fuzzy => 0.65,
            self::None => 0.0,
        };
    }

    public function isStrong(): bool
    {
        return in_array($this, [self::ScientificExact, self::Synonym, self::ConstrainedPick], true);
    }
}
