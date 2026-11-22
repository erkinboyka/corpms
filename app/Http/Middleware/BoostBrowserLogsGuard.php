<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-04: страховка для dev-роута Laravel Boost /_boost/browser-logs.
 *
 * Vendor-роут регистрируется без auth/CSRF и принимает произвольный лог с
 * браузера. Мы оставляем его только в local, только с явным browser watcher
 * и только для IP из allowlist, плюс собственный rate limit 10/минуту.
 */
class BoostBrowserLogsGuard
{
    private const LIMIT = 10;

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')
            || ! (bool) config('boost.enabled')
            || ! (bool) config('boost.browser_logs_watcher')) {
            abort(404);
        }

        $allowed = config('boost.browser_logs_allowed_ips', []);
        if (! is_array($allowed) || ! in_array($request->ip(), $allowed, true)) {
            abort(403);
        }

        $key = 'boost.browser-logs:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            abort(429);
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
