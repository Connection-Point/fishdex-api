<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Str;

/**
 * Normalises fish names so "Ikan Toman", "toman" and "TOMAN " match,
 * and scientific names with authorities ("Channa micropeltes (Cuvier, 1831)")
 * reduce to "channa micropeltes".
 */
final class NameNormalizer
{
    /** Malay/English generic prefixes that carry no identity. */
    private const PREFIXES = ['ikan ', 'anak ', 'the '];

    public static function common(string $name): string
    {
        $name = Str::lower(Str::ascii(trim($name)));
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = trim($name);

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }
        }

        return trim($name);
    }

    public static function scientific(string $name): string
    {
        $name = Str::lower(Str::ascii(trim($name)));
        // Drop authority in parentheses or trailing "Author, 1831".
        $name = preg_replace('/\(.*?\)/', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z ]+/', ' ', $name) ?? $name;
        $parts = array_values(array_filter(explode(' ', $name)));

        // Canonical key = genus + species epithet. Subspecies are not catalogued separately in the MVP.
        return implode(' ', array_slice($parts, 0, 2));
    }

    public static function genus(string $scientific): string
    {
        return explode(' ', self::scientific($scientific))[0] ?? '';
    }
}
