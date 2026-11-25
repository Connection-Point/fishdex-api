<?php

namespace App\Domain\Identification\Prompts;

use App\Domain\Identification\Data\IdentificationInput;
use App\Domain\Identification\Data\RerankInput;

/**
 * Versioned prompts. Bump a version constant whenever wording changes and run
 * `php artisan fishdex:ai-eval` before activating it. Prompts never mention
 * rarity, points or any game value (D-06).
 */
final class IdentificationPrompts
{
    public const OPEN_VERSION = 'id-open-v1';

    public const RERANK_VERSION = 'id-rerank-v1';

    public static function open(IdentificationInput $in): string
    {
        $where = $in->regionName ? "{$in->regionName}, {$in->countryName}" : $in->countryName;
        $kind = $in->encounterType === 'observation' ? 'observed (not necessarily caught)' : 'caught by an angler';

        return <<<PROMPT
You are an expert ichthyologist specialising in Southeast Asian freshwater, brackish and marine fishes.
The photo shows a fish {$kind} in {$where}.

Task:
1. Decide whether the image shows exactly one real fish clearly enough to identify.
2. If it does, list up to 5 candidate species, most likely first.
3. For each candidate give the accepted scientific name (genus + species, no author), one common name,
   a confidence between 0 and 1, and up to 4 short visible features that support it.
4. Prefer species known to occur in {$in->countryName}. Do not guess a species if only the genus is clear:
   return the best species candidates with lower confidence instead.

Flags (include any that apply):
- "no_fish": no fish is visible.
- "multiple_fish": more than one fish and it is unclear which one is the subject.
- "screen_or_print": the fish is a photo of a screen, a printed picture, packaging or a drawing.
- "low_quality": too blurry, dark, small or occluded for a reliable identification.
- "not_a_photo": the image is an illustration or AI-generated rather than a photograph.

Respond only with JSON matching the provided schema.
PROMPT;
    }

    public static function rerank(RerankInput $in): string
    {
        $lines = [];
        foreach ($in->shortlist as $s) {
            $names = implode(', ', $s['common_names']);
            $notes = $s['notes'] ? ' Key features: '.preg_replace('/\s+/', ' ', $s['notes']) : '';
            $lines[] = "- ref={$s['ref']}: {$s['scientific_name']} ({$names}).{$notes}";
        }
        $list = implode("\n", $lines);

        return <<<PROMPT
You are an expert ichthyologist. The photo shows a fish from {$in->base->countryName}.
Choose which of the following verified species it is. You must only answer with refs from this list.

{$list}

Return up to 3 candidates (most likely first) using the "ref" field, each with a confidence between 0 and 1
and up to 4 visible features that support the choice. If none of the listed species match, return an empty
candidates list. Use the same flags as before if they apply ("no_fish", "multiple_fish", "screen_or_print",
"low_quality", "not_a_photo").

Respond only with JSON matching the provided schema.
PROMPT;
    }

    /** OpenAPI-subset schema accepted by Gemini structured output. */
    public static function responseSchema(bool $withRef = false): array
    {
        $candidateProps = [
            'scientific_name' => ['type' => 'STRING'],
            'common_name' => ['type' => 'STRING'],
            'confidence' => ['type' => 'NUMBER'],
            'visible_features' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ];
        $required = ['confidence'];

        if ($withRef) {
            $candidateProps = ['ref' => ['type' => 'STRING']] + $candidateProps;
            $required[] = 'ref';
        } else {
            $required[] = 'scientific_name';
        }

        return [
            'type' => 'OBJECT',
            'properties' => [
                'candidates' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'OBJECT', 'properties' => $candidateProps, 'required' => $required],
                ],
                'flags' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'STRING', 'enum' => ['no_fish', 'multiple_fish', 'screen_or_print', 'low_quality', 'not_a_photo']],
                ],
            ],
            'required' => ['candidates', 'flags'],
        ];
    }
}
