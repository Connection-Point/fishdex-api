<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replays the original response for a repeated Idempotency-Key (24 h), so
 * flaky mobile networks can safely retry POSTs. Only 2xx responses are stored.
 */
class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw Problem::make(400, 'idempotency_key_required', 'Idempotency-Key header is required for this request.');
        }
        if (strlen($key) > 100) {
            throw Problem::make(400, 'idempotency_key_invalid', 'Idempotency-Key is too long.');
        }

        $cacheKey = 'idem:'.($request->user()?->getAuthIdentifier() ?? 'guest').':'.sha1($request->method().$request->path().$key);

        if ($cached = Cache::get($cacheKey)) {
            return response($cached['body'], $cached['status'], $cached['headers'] + ['Idempotent-Replayed' => 'true']);
        }

        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            throw Problem::make(409, 'request_in_progress', 'An identical request is still being processed.');
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'headers' => ['Content-Type' => $response->headers->get('Content-Type')],
                ], now()->addDay());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
