<?php

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-tunable settings: app_settings row overrides config('fishdex.*').
 * Keys use config dot-notation, e.g. "confidence.high", "ai.kill_switch".
 */
class SettingsService
{
    private const CACHE_KEY = 'fishdex:app_settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return config("fishdex.{$key}", $default);
    }

    public function set(string $key, mixed $value, ?int $adminId = null): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by_admin_id' => $adminId]);
        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key): void
    {
        AppSetting::query()->whereKey($key)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, fn () => AppSetting::query()->pluck('value', 'key')->all());
    }

    public function aiTier(): string
    {
        return $this->get('ai.tier', 'free') === 'paid' ? 'paid' : 'free';
    }
}
