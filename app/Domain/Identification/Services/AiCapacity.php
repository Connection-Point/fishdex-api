<?php

namespace App\Domain\Identification\Services;

use App\Domain\Settings\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps us inside the provider's request limits.
 *  - free tier: RPM and RPD (requests/day resets at midnight Pacific) — D-16
 *  - paid tier: RPM only (spend is guarded by AiBudgetGuard)
 */
class AiCapacity
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{granted: bool, retry_at: ?CarbonImmutable, reason: ?string} */
    public function acquire(): array
    {
        if (Cache::get($this->cooldownKey())) {
            return $this->deny(CarbonImmutable::now()->addMinute(), 'provider_cooldown');
        }

        if ($this->dailyLimit() !== null && $this->usedToday() >= $this->dailyLimit()) {
            return $this->deny($this->nextDailyReset(), 'daily_limit');
        }

        $minuteKey = 'ai:cap:rpm:'.now()->format('YmdHi');
        Cache::add($minuteKey, 0, 120);
        if (Cache::get($minuteKey, 0) >= $this->perMinuteLimit()) {
            return $this->deny(CarbonImmutable::now()->startOfMinute()->addMinute(), 'minute_limit');
        }

        Cache::increment($minuteKey);
        if ($this->dailyLimit() !== null) {
            Cache::add($this->dayKey(), 0, 60 * 60 * 26);
            Cache::increment($this->dayKey());
        }

        return ['granted' => true, 'retry_at' => null, 'reason' => null];
    }

    /** Provider answered 429: back off; three in one quota-day means the day is exhausted. */
    public function recordRateLimited(): CarbonImmutable
    {
        $key = $this->dayKey().':429';
        Cache::add($key, 0, 60 * 60 * 26);
        $count = Cache::increment($key);

        if ($count >= 3 && $this->dailyLimit() !== null) {
            Cache::put($this->dayKey(), $this->dailyLimit(), 60 * 60 * 26);

            return $this->nextDailyReset();
        }

        Cache::put($this->cooldownKey(), true, 60);

        return CarbonImmutable::now()->addMinute();
    }

    public function remainingToday(): ?int
    {
        $limit = $this->dailyLimit();

        return $limit === null ? null : max(0, $limit - $this->usedToday());
    }

    public function perMinuteLimit(): int
    {
        return (int) ($this->isFree() ? $this->settings->get('ai.free_tier.rpm', 10) : $this->settings->get('ai.paid.rpm', 500));
    }

    public function dailyLimit(): ?int
    {
        if (! $this->isFree()) {
            return null;
        }

        $rpd = (int) $this->settings->get('ai.free_tier.rpd', 250);
        $ratio = (float) $this->settings->get('ai.free_tier.usable_ratio', 0.8);

        return (int) floor($rpd * $ratio);
    }

    public function usedToday(): int
    {
        return (int) Cache::get($this->dayKey(), 0);
    }

    public function nextDailyReset(): CarbonImmutable
    {
        $tz = $this->settings->get('ai.free_tier.reset_timezone', 'America/Los_Angeles');

        return CarbonImmutable::now($tz)->startOfDay()->addDay()->utc();
    }

    private function isFree(): bool
    {
        return $this->settings->aiTier() === 'free';
    }

    private function dayKey(): string
    {
        $tz = $this->settings->get('ai.free_tier.reset_timezone', 'America/Los_Angeles');

        return 'ai:cap:rpd:'.CarbonImmutable::now($tz)->format('Ymd');
    }

    private function cooldownKey(): string
    {
        return 'ai:cap:cooldown';
    }

    private function deny(CarbonImmutable $retryAt, string $reason): array
    {
        return ['granted' => false, 'retry_at' => $retryAt, 'reason' => $reason];
    }
}
