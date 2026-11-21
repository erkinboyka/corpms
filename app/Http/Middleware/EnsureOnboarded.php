<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        // Разрешаем маршруты онбординга и выхода.
        $routeName = $request->route()?->getName();
        if (in_array($routeName, ['onboarding.index', 'onboarding.store', 'onboarding.skip', 'logout', 'locale.switch'], true)) {
            return $next($request);
        }

        $me = app(PermissionService::class)->employee($user);
        if ($me === null) {
            return $next($request);
        }

        $company = Company::find($me->company_id);
        if ($company && $company->onboarding_completed_at === null) {
            return redirect()->route('onboarding.index');
        }

        return $next($request);
    }
}