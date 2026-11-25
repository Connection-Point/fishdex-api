<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('fishdex.supported_locales');
        $locale = $request->getPreferredLanguage($supported) ?? $request->user()?->locale ?? 'en';

        app()->setLocale(in_array($locale, $supported, true) ? $locale : 'en');

        return $next($request);
    }
}
