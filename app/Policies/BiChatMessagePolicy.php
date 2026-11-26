<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BiChatMessage;
use App\Models\User;
use App\Support\PermissionService;
use App\Support\TenantGuard;

/** Сообщение Bumi доступно только в рамках своего дашборда и тенанта. */
final class BiChatMessagePolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function view(User $user, BiChatMessage $message): bool
    {
        return $this->permissions->check($user, 'bi.view')
            && TenantGuard::owns($user->employee, $message, ['dashboard']);
    }

    public function update(User $user, BiChatMessage $message): bool
    {
        return $this->permissions->check($user, 'bi.manage@company')
            && TenantGuard::owns($user->employee, $message, ['dashboard']);
    }

    /** Оценку ответа ставит любой пользователь с доступом к BI в этом тенанте. */
    public function rate(User $user, BiChatMessage $message): bool
    {
        return $this->view($user, $message);
    }
}
