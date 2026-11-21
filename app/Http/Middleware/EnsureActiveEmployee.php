<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Shared\Enums\EmployeeStatus;
use App\Support\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve current database state on every request, including remembered sessions.
        app(PermissionService::class)->forget();
        $user = $request->user();
        $employee = $user?->employee()->first();
        $expired = false;
        if ($user !== null) {
            $currentVersion = (int) User::query()->whereKey($user->id)->value('auth_version');
            $sessionVersion = $request->session()->get('auth_version');
            // Sessions issued before versioning must reauthenticate. A guard injected
            // without a persisted login (e.g. actingAs in tests) has no session login key.
            $expired = $sessionVersion === null
                ? $request->session()->has(Auth::guard()->getName())
                : (int) $sessionVersion !== $currentVersion;
            if (! $expired) {
                $request->session()->put('auth_version', $currentVersion);
            }
        }
        if ($expired || ($employee !== null && $employee->status !== EmployeeStatus::Active)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => __('Доступ к учётной записи отключён')], 403)
                : redirect()->route('login')->withErrors(['email' => __('Доступ к учётной записи отключён')]);
        }

        return $next($request);
    }
}
