<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Aggregates identification_attempts into ai_usage_daily for the cost dashboard. */
class RollupAiUsage extends Command
{
    protected $signature = 'fishdex:rollup-ai-usage {--date= : YYYY-MM-DD (default: today and yesterday)}';

    protected $description = 'Roll up AI usage and cost per provider/model/day';

    public function handle(): int
    {
        $dates = $this->option('date') ? [Carbon::parse($this->option('date'))] : [today()->subDay(), today()];

        foreach ($dates as $date) {
            $rows = DB::table('identification_attempts')
                ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
                ->groupBy('provider', 'model')
                ->get([
                    'provider', 'model',
                    DB::raw('COUNT(*) as requests'),
                    DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes"),
                    DB::raw("SUM(CASE WHEN status IN ('error','timeout','invalid_output') THEN 1 ELSE 0 END) as failures"),
                    DB::raw("SUM(CASE WHEN status = 'rate_limited' THEN 1 ELSE 0 END) as rate_limited"),
                    DB::raw('SUM(input_tokens) as input_tokens'),
                    DB::raw('SUM(output_tokens) as output_tokens'),
                    DB::raw('SUM(cost_usd_micros) as cost_usd_micros'),
                ]);

            foreach ($rows as $r) {
                $latencies = DB::table('identification_attempts')
                    ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
                    ->where('provider', $r->provider)->where('model', $r->model)->where('status', 'success')
                    ->orderBy('latency_ms')->pluck('latency_ms');

                DB::table('ai_usage_daily')->updateOrInsert(
                    ['date' => $date->toDateString(), 'provider' => $r->provider, 'model' => $r->model],
                    [
                        'requests' => $r->requests, 'successes' => $r->successes, 'failures' => $r->failures,
                        'rate_limited' => $r->rate_limited, 'input_tokens' => $r->input_tokens,
                        'output_tokens' => $r->output_tokens, 'cost_usd_micros' => $r->cost_usd_micros,
                        'p50_latency_ms' => $this->percentile($latencies->all(), 0.5),
                        'p95_latency_ms' => $this->percentile($latencies->all(), 0.95),
                        'updated_at' => now(), 'created_at' => now(),
                    ],
                );
            }
        }

        return self::SUCCESS;
    }

    private function percentile(array $sorted, float $p): ?int
    {
        if (! $sorted) {
            return null;
        }

        return (int) $sorted[(int) min(count($sorted) - 1, floor($p * count($sorted)))];
    }
}
