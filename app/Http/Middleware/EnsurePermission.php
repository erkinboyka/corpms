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

        $slug = $permission;
        $required = null;

        if (str_contains($permission, '@')) {
            [$slug, $scopeName] = explode('@', $permission, 2);
            $required = PermissionScope::tryFrom($scopeName);

            if ($required === null || $slug === '') {
                // ошибка конфигурации маршрута: лучше упасть, чем молча пропустить
                abort(500, 'Некорректный scope в праве маршрута: '.$permission);
            }
        }

        $allowed = $required === null
            ? $this->permissions->check($user, $slug)
            : $this->permissions->checkScope($user, $slug, $required);

        if ($allowed === false) {
            abort(403, 'Недостаточно прав: '.$permission);
        }

        return $next($request);
    }
}
