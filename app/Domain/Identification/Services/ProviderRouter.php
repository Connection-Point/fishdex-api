<?php

namespace App\Domain\Identification\Services;

use App\Domain\Identification\Contracts\IdentificationProvider;
use App\Domain\Identification\Providers\FakeProvider;
use App\Domain\Identification\Providers\GeminiProvider;
use App\Domain\Settings\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Chooses primary/fallback providers and applies a simple Redis-backed circuit breaker.
 */
class ProviderRouter
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<IdentificationProvider> */
    public function providers(): array
    {
        $names = array_values(array_unique(array_filter([
            $this->settings->get('ai.provider'),
            $this->settings->get('ai.fallback_provider'),
        ])));

        $available = array_values(array_filter($names, fn (string $n) => ! $this->isOpen($n)));

        // If every breaker is open, still try the primary rather than failing outright.
        return array_map(fn (string $n) => $this->make($n), $available ?: array_slice($names, 0, 1));
    }

    public function make(string $name): IdentificationProvider
    {
        return match ($name) {
            'gemini' => new GeminiProvider(config('fishdex.ai.providers.gemini')),
            'fake' => new FakeProvider,
            default => throw new InvalidArgumentException("Unknown AI provider [{$name}]."),
        };
    }

    public function recordSuccess(string $name): void
    {
        Cache::forget("ai:cb:{$name}:fails");
    }

    public function recordFailure(string $name): void
    {
        $cfg = config('fishdex.ai.circuit_breaker');
        $key = "ai:cb:{$name}:fails";

        Cache::add($key, 0, $cfg['window_seconds']);
        $fails = Cache::increment($key);

        if ($fails >= $cfg['failures']) {
            Cache::put("ai:cb:{$name}:open_until", now()->addSeconds($cfg['cooldown_seconds'])->getTimestamp(), $cfg['cooldown_seconds']);
            Cache::forget($key);
        }
    }

    public function isOpen(string $name): bool
    {
        $until = Cache::get("ai:cb:{$name}:open_until");

        return $until !== null && $until > now()->getTimestamp();
    }
}
