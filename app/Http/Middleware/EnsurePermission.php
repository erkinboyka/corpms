<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * usage: ->middleware('permission:tasks.view') / 'permission:audit.view@company' / 'employee.auth'
 *
 * A7: после слага можно указать минимальный scope — `permission:slug@scope`
 * (own < department < company). Без суффикса проверяется лишь наличие права
 * (любой scope) — обратная совместимость.
 */
class EnsurePermission
{
    public function __construct(protected PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $scope = null;
        if (str_contains($permission, '@')) {
            [$permission, $scopeName] = explode('@', $permission, 2);
            $scope = PermissionScope::tryFrom($scopeName);

            if ($scope === null || $permission === '') {
                abort(500, 'Некорректный scope в праве маршрута: '.$permission.'@'.$scopeName);
            }
        }

        $slugs = array_values(array_filter(array_map('trim', explode('|', $permission)), static fn (string $slug): bool => $slug !== ''));

        if ($slugs === []) {
            abort(500, 'Некорректное право маршрута: '.$permission);
        }

        $allowed = false;
        foreach ($slugs as $slug) {
            $ok = $scope === null
                ? $this->permissions->check($user, $slug)
                : $this->permissions->checkScope($user, $slug, $scope);

            if ($ok) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            abort(403, 'Недостаточно прав: '.implode('|', $slugs));
        }

        return $next($request);
    }
}
