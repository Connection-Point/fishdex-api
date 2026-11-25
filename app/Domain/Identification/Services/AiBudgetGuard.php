<?php

namespace App\Domain\Identification\Services;

use App\Domain\Identification\Models\IdentificationAttempt;
use App\Domain\Settings\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Global AI spend guard + kill-switch (D-15). Free tier has no spend, only capacity.
 */
class AiBudgetGuard
{
    public function __construct(private readonly SettingsService $settings) {}

    public function killSwitchOn(): bool
    {
        return (bool) $this->settings->get('ai.kill_switch', false);
    }

    public function allowsNewScans(): bool
    {
        if ($this->killSwitchOn()) {
            return false;
        }

        if ($this->settings->aiTier() === 'free') {
            return true;
        }

        $budgetMicros = (int) round((float) $this->settings->get('ai.paid.daily_budget_usd', 20) * 1_000_000);
        $spent = $this->spentTodayMicros();

        if ($spent >= $budgetMicros * (float) $this->settings->get('ai.paid.budget_alert_ratio', 0.8)
            && Cache::add('ai:budget:alerted:'.now()->toDateString(), true, 86400)) {
            Log::warning('FishDex AI spend passed alert threshold', ['spent_usd' => $spent / 1e6, 'budget_usd' => $budgetMicros / 1e6]);
        }

        return $spent < $budgetMicros;
    }

    public function spentTodayMicros(): int
    {
        return (int) Cache::remember('ai:budget:spent:'.now()->toDateString(), 60, fn () => IdentificationAttempt::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('cost_usd_micros'));
    }
}
