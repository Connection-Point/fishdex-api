<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Scheduled maintenance for hosts without a long-running scheduler (Vercel Cron).
 * Vercel sends "Authorization: Bearer <CRON_SECRET>" when CRON_SECRET is set on the project.
 */
class CronController extends Controller
{
    private const DAILY = [
        'fishdex:sweep-stuck-scans',
        'fishdex:release-waiting-scans',
        'fishdex:rollup-ai-usage',
        'fishdex:purge',
    ];

    public function daily(Request $request): JsonResponse
    {
        $secret = (string) config('fishdex.runtime.cron_secret');
        abort_if($secret === '' || ! hash_equals('Bearer '.$secret, (string) $request->header('Authorization')), 404);

        $results = [];
        foreach (self::DAILY as $command) {
            try {
                $results[$command] = Artisan::call($command) === 0 ? 'ok' : 'failed';
            } catch (Throwable $e) {
                report($e);
                $results[$command] = 'error';
            }
        }

        return response()->json(['ran' => $results]);
    }
}
