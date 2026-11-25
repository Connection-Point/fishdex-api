<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Updates last_active_at at most every 15 minutes; blocks suspended / pending-deletion users. */
class TouchLastActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            $user->currentAccessToken()?->delete();
            abort(403, 'This account is not active.');
        }

        if ($user && (! $user->last_active_at || $user->last_active_at->lt(now()->subMinutes(15)))) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
