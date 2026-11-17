<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Shared\Enums\PermissionScope;
use App\Shared\Permissions\PermissionMatrix;
use Illuminate\Support\Collection;

/**
 * Центральный сервис авторизации: RBAC + scopes (OWN / DEPARTMENT / COMPANY).
 *
 * Разрешение = union(scope) по ролям сотрудника, персональные override'ы
 * могут только расширять доступ.
 */
class PermissionService
{
    /**
     * Мемоизация в рамках одного запроса (сервис — singleton): рендер сайдбара
     * спрашивает права десятки раз; без кэша это давало ~70 SQL-запросов на страницу.
     *
     * @var array<int, Employee>
     */
    protected array $employeeCache = [];

    /** @var array<int, Collection> */
    protected array $roleNamesCache = [];

    /** @var array<string, PermissionScope|false> */
    protected array $canCache = [];

    public function can(User $user, string $permission): PermissionScope|false
    {
        $key = $user->id.'|'.$permission;

        if (array_key_exists($key, $this->canCache)) {
            return $this->canCache[$key];
        }

        return $this->canCache[$key] = $this->resolveCan($user, $permission);
    }

    protected function resolveCan(User $user, string $permission): PermissionScope|false
    {
        if ($this->roleNames($user)->contains('super_admin')) {
            return PermissionScope::Company;
        }

        if (PermissionMatrix::exists($permission) === false) {
            return false;
        }

        $employee = $this->employee($user);
        if ($employee === null) {
            return false;
        }

        $roleIds = EmployeeRole::query()->where('employee_id', $employee->id)->pluck('role_id');
        if ($roleIds->isEmpty()) {
            return false;
        }

        $permissionId = Permission::query()->where('slug', $permission)->value('id');
        if ($permissionId === null) {
            return false;
        }

        $best = false;

        $roleScopes = RolePermission::query()
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->pluck('scope');

        foreach ($roleScopes as $scope) {
            $candidate = $this->asScope($scope);
            $best = $best === false || $candidate->value > $best->value ? $candidate : $best;
        }

        $overrideScope = EmployeePermissionOverride::query()
            ->where('employee_id', $employee->id)
            ->where('permission_id', $permissionId)
            ->value('scope');

        if ($overrideScope !== null) {
            $candidate = $this->asScope($overrideScope);
            $best = $best === false || $candidate->value > $best->value ? $candidate : $best;
        }

        return $best;
    }

    /** Принимает и строку, и уже приведённый enum (pluck considerar casts). */
    protected function asScope(mixed $scope): PermissionScope
    {
        return $scope instanceof PermissionScope ? $scope : PermissionScope::from($scope);
    }

    /** Разрешение есть хотя бы с каким-то scope. */
    public function check(User $user, string $permission): bool
    {
        return $this->can($user, $permission) !== false;
    }

    /** Есть ли право в таком же или более широком scope. */
    public function checkScope(User $user, string $permission, PermissionScope $required): bool
    {
        $actual = $this->can($user, $permission);

        return $actual !== false && $actual->rank() >= $required->rank();
    }

    public function employee(User $user): ?Employee
    {
        if (array_key_exists($user->id, $this->employeeCache)) {
            return $this->employeeCache[$user->id];
        }

        return $this->employeeCache[$user->id] = $user->employee()->first();
    }

    /** Slug'и ролей сотрудника. */
    public function roleNames(User $user): Collection
    {
        $employee = $this->employee($user);
        if ($employee === null) {
            return collect();
        }

        if (array_key_exists($employee->id, $this->roleNamesCache)) {
            return $this->roleNamesCache[$employee->id];
        }

        return $this->roleNamesCache[$employee->id] = Role::query()
            ->join('employee_roles', 'employee_roles.role_id', '=', 'roles.id')
            ->where('employee_roles.employee_id', $employee->id)
            ->pluck('slug');
    }

    protected function widest(PermissionScope|false $a, PermissionScope|false $b): PermissionScope|false
    {
        if ($a === false) {
            return $b;
        }
        if ($b === false) {
            return $a;
        }

        return ($a->rank() > $b->rank()) ? $a : $b;
    }
}
