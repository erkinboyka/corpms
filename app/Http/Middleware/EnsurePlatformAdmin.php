<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-level gate for installation-wide resources.
 *
 * Backups contain the whole database across every tenant, so a company-scoped
 * permission (settings.manage@company) must never be enough. Only the
 * platform super_admin role is accepted; per-employee overrides cannot widen
 * this gate.
 */
final class EnsurePlatformAdmin
{
    public function __construct(protected PermissionService $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if (! $this->permissions->roleNames($user)->contains('super_admin')) {
            abort(403, 'Только платформенный администратор имеет доступ к ресурсу.');
        }

        return $next($request);
    }
}
