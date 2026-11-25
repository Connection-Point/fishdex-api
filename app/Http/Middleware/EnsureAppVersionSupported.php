<?php

namespace App\Http\Middleware;

use App\Domain\Settings\Services\SettingsService;
use App\Domain\Shared\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Old clients get 426 so the app can force an update. Requests without the header pass (tools, health). */
class EnsureAppVersionSupported
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-App-Version');
        if ($header && ! $request->is('api/v1/app/config', 'api/v1/health')) {
            $version = explode('+', $header)[0];
            $min = (string) $this->settings->get('min_supported_app_version', '1.0.0');

            if (preg_match('/^\d+\.\d+\.\d+/', $version) && version_compare($version, $min, '<')) {
                throw Problem::make(426, 'app_version_unsupported', 'Please update FishDex to continue.', ['min_supported_version' => $min]);
            }
        }

        return $next($request);
    }
}
