<?php

namespace App\Filament\Widgets;

use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identification\Services\AiBudgetGuard;
use App\Domain\Identification\Services\AiCapacity;
use App\Domain\Identity\Models\User;
use App\Domain\Review\Models\ReviewItem;
use App\Domain\Settings\Services\SettingsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: AI capacity/cost, scan pipeline health and moderation backlog. */
class AiUsageOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $settings = app(SettingsService::class);
        $capacity = app(AiCapacity::class);
        $budget = app(AiBudgetGuard::class);
        $today = now('Asia/Kuala_Lumpur')->startOfDay()->utc();

        $scansToday = IdentificationRequest::query()->where('created_at', '>=', $today)->count();
        $byStatus = IdentificationRequest::query()->where('created_at', '>=', $today)
            ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $bands = IdentificationRequest::query()->where('created_at', '>=', $today)->whereNotNull('confidence_band')
            ->select('confidence_band', DB::raw('count(*) as c'))->groupBy('confidence_band')->pluck('c', 'confidence_band');

        $p95 = DB::table('identification_attempts')->where('created_at', '>=', $today)->where('status', 'success')
            ->orderBy('latency_ms')->pluck('latency_ms');
        $p95Value = $p95->isEmpty() ? null : $p95[(int) min($p95->count() - 1, floor($p95->count() * 0.95))];

        $aiStat = $settings->aiTier() === 'free'
            ? Stat::make('Free AI requests today', $capacity->usedToday().' / '.$capacity->dailyLimit())
                ->description('Resets '.$capacity->nextDailyReset()->setTimezone('Asia/Kuala_Lumpur')->format('H:i').' MYT')
                ->color($capacity->remainingToday() < 10 ? 'danger' : 'success')
            : Stat::make('AI spend today', '$'.number_format($budget->spentTodayMicros() / 1e6, 2))
                ->description('Budget $'.number_format((float) $settings->get('ai.paid.daily_budget_usd'), 2));

        return [
            $aiStat,
            Stat::make('Scans today', $scansToday)
                ->description(sprintf('%d waiting · %d failed', $byStatus['waiting_capacity'] ?? 0, ($byStatus['failed'] ?? 0) + ($byStatus['timed_out'] ?? 0)))
                ->color(($byStatus['waiting_capacity'] ?? 0) > 0 ? 'warning' : 'gray'),
            Stat::make('Confidence today', sprintf('H %d · M %d · L %d', $bands['high'] ?? 0, $bands['medium'] ?? 0, $bands['low'] ?? 0))
                ->description('AI p95 latency: '.($p95Value !== null ? round($p95Value / 1000, 1).' s' : '—')),
            Stat::make('Open reviews', ReviewItem::query()->where('status', 'open')->count()),
            Stat::make('Anglers active (7 days)', User::query()->where('last_active_at', '>=', now()->subDays(7))->count())
                ->description(User::query()->count().' total'),
            Stat::make('Scanning', $budget->killSwitchOn() ? 'PAUSED' : 'Live')->color($budget->killSwitchOn() ? 'danger' : 'success'),
        ];
    }
}
