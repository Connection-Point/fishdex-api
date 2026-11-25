<?php

namespace App\Domain\Identification\Services;

/**
 * Tokens → integer micro-USD. Price table is USD per 1M tokens, which is
 * numerically the same as micro-USD per token.
 */
class CostCalculator
{
    public function micros(string $model, string $tier, int $inputTokens, int $outputTokens): int
    {
        if ($tier === 'free') {
            return 0;
        }

        // Model IDs contain dots ("gemini-3.6-flash"), so no dot-notation lookup here.
        $price = config('fishdex.ai.prices', [])[$model] ?? null;
        if (! $price) {
            return 0;
        }

        return (int) round($inputTokens * $price['input'] + $outputTokens * $price['output']);
    }
}
