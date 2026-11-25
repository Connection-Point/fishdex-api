<?php

use App\Domain\Shared\Support\Problem;
use App\Http\Middleware\EnsureAppVersionSupported;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Http\Middleware\TouchLastActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a hosting proxy (Vercel, Cloudflare): TRUSTED_PROXIES=* so HTTPS URLs and client IPs are right.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->api(append: [
            SetLocaleFromHeader::class,
            EnsureAppVersionSupported::class,
        ]);
        $middleware->alias([
            'idempotent' => IdempotencyKey::class,
            'touch' => TouchLastActive::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('admin*') ? '/admin/login' : null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api', 'api/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request) || $request->expectsJson());

        // RFC 9457 problem+json with a stable machine-readable `code` (doc 07 §1).
        $problem = function (int $status, string $code, string $detail, array $extra = [], array $headers = []) {
            return response()->json(array_merge([
                'type' => 'https://fishdex.app/problems/'.str_replace('_', '-', $code),
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'status' => $status,
                'detail' => $detail,
                'code' => $code,
            ], $extra), $status, array_merge(['Content-Type' => 'application/problem+json'], $headers));
        };

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi, $problem) {
            if (! $isApi($request)) {
                return null;
            }

            return match (true) {
                $e instanceof Problem => $problem($e->getStatusCode(), $e->problemCode, $e->getMessage(), $e->extra, $e->getHeaders()),
                $e instanceof ValidationException => $problem(422, 'validation_failed', 'The given data was invalid.', ['errors' => $e->errors()]),
                $e instanceof AuthenticationException => $problem(401, 'unauthenticated', 'Authentication required.'),
                $e instanceof AuthorizationException => $problem(403, 'forbidden', 'You are not allowed to do that.'),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $problem(404, 'not_found', 'Not found.'),
                $e instanceof ThrottleRequestsException => $problem(429, 'rate_limited', 'Too many requests. Slow down a little.', [], $e->getHeaders()),
                $e instanceof HttpExceptionInterface => $problem($e->getStatusCode(), 'http_error', $e->getMessage() ?: 'Request failed.', [], $e->getHeaders()),
                default => config('app.debug') ? null : $problem(500, 'server_error', 'Something went wrong on our side.'),
            };
        });
    })->create();
