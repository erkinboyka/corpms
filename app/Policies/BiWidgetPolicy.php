<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BiWidget;
use App\Models\User;
use App\Support\PermissionService;
use App\Support\TenantGuard;

/**
 * Виджет не имеет собственного company_id — владение определяется
 * дашбордом. Используется и обычными CRUD, и будущими tool calls Bumi.
 */
final class BiWidgetPolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->check($user, 'bi.view');
    }

    public function view(User $user, BiWidget $widget): bool
    {
        return $this->viewAny($user)
            && TenantGuard::owns($user->employee, $widget, ['dashboard']);
    }

    public function create(User $user): bool
    {
        return $this->permissions->check($user, 'bi.manage@company');
    }

    public function update(User $user, BiWidget $widget): bool
    {
        return $this->create($user)
            && TenantGuard::owns($user->employee, $widget, ['dashboard']);
    }

    public function delete(User $user, BiWidget $widget): bool
    {
        return $this->update($user, $widget);
    }
}
