<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BiDashboard;
use App\Models\User;
use App\Support\PermissionService;
use App\Support\TenantGuard;

/**
 * Первая политика BI: права + принадлежность дашборда компании пользователя.
 * Дополняет TenantGuard в контроллерах, но не заменяет middleware изоляции.
 */
final class BiDashboardPolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->check($user, 'bi.view');
    }

    public function view(User $user, BiDashboard $dashboard): bool
    {
        return $this->viewAny($user)
            && TenantGuard::owns($user->employee, $dashboard);
    }

    public function create(User $user): bool
    {
        return $this->permissions->check($user, 'bi.manage@company');
    }

    public function update(User $user, BiDashboard $dashboard): bool
    {
        return $this->create($user)
            && TenantGuard::owns($user->employee, $dashboard);
    }

    public function delete(User $user, BiDashboard $dashboard): bool
    {
        return $this->update($user, $dashboard);
    }
}
