<?php

namespace App\Domain\Identification\Services;

use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingsService;
use Carbon\CarbonImmutable;

/**
 * Per-user daily scan quota, counted on the user's local day.
 * Failed and rejected scans don't count against the user.
 */
class ScanQuota
{
    public function __construct(private readonly SettingsService $settings) {}

    public function limitFor(User $user): int
    {
        $limit = (int) $this->settings->get("quotas.daily.{$user->plan}", $this->settings->get('quotas.daily.free', 5));

        $newHours = (int) $this->settings->get('quotas.new_account_hours', 24);
        if ($user->created_at && $user->created_at->gt(now()->subHours($newHours))) {
            $limit = min($limit, (int) $this->settings->get('quotas.new_account_daily', 3));
        }

        return $limit;
    }

    public function usedToday(User $user): int
    {
        [$start, $end] = $this->window($user);

        return IdentificationRequest::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->whereNotIn('status', ['failed', 'rejected'])
            ->count();
    }

    /** @return array{limit: int, used: int, remaining: int, resets_at: string} */
    public function status(User $user): array
    {
        $limit = $this->limitFor($user);
        $used = $this->usedToday($user);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'resets_at' => $this->window($user)[1]->utc()->toIso8601String(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(User $user): array
    {
        $now = CarbonImmutable::now($user->timezone ?: 'Asia/Kuala_Lumpur');

        return [$now->startOfDay(), $now->startOfDay()->addDay()];
    }
}
