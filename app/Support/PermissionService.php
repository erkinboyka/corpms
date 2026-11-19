<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\EmployeeStatus;
use App\Shared\Enums\PermissionScope;
use App\Shared\Permissions\PermissionMatrix;
use Illuminate\Database\Eloquent\Builder;
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
     * Мемоизация в рамках одного запроса (сервис — scoped): рендер сайдбара
     * спрашивает права десятки раз; без кэша это давало ~70 SQL-запросов на страницу.
     *
     * @var array<int, Employee>
     */
    protected array $employeeCache = [];

    /** @var array<int, Collection> */
    protected array $roleNamesCache = [];

    /** @var array<string, PermissionScope|false> */
    protected array $canCache = [];

    /** @var array<int, array<string, PermissionScope>> */
    protected array $effectiveCache = [];

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
        if ($this->employee($user)?->status !== EmployeeStatus::Active) {
            return false;
        }
        // 'slug@scope' — минимальный scope (та же форма, что в EnsurePermission):
        // сайдбар и маршруты пишут права единообразно; без суффикса — как
        // раньше, проверяется лишь наличие права в любом scope.
        $required = null;

        if (str_contains($permission, '@')) {
            [$slug, $scopeName] = explode('@', $permission, 2);
            $required = PermissionScope::tryFrom($scopeName);

            if ($required === null || $slug === '') {
                // ошибка конфигурации: лучше закрыть доступ, чем молча пропустить
                return false;
            }

            $permission = $slug;
        }

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

        if ($this->roleNames($user)->isEmpty()) {
            return false;
        }

        if (! array_key_exists($employee->id, $this->effectiveCache)) {
            $grants = Permission::query()
                ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->join('employee_roles', 'employee_roles.role_id', '=', 'role_permissions.role_id')
                ->where('employee_roles.employee_id', $employee->id)
                ->get(['permissions.slug', 'role_permissions.scope']);
            $overrides = EmployeePermissionOverride::query()
                ->join('permissions', 'permissions.id', '=', 'employee_permission_overrides.permission_id')
                ->where('employee_id', $employee->id)->get(['permissions.slug', 'employee_permission_overrides.scope']);
            $effective = [];
            foreach ($grants->concat($overrides) as $grant) {
                $effective[$grant->slug] = $this->widest($effective[$grant->slug] ?? false, $this->asScope($grant->scope));
            }
            $this->effectiveCache[$employee->id] = $effective;
        }

        $best = $this->effectiveCache[$employee->id][$permission] ?? false;

        if (PermissionMatrix::requiresCompanyScope($permission)) {
            $required = PermissionScope::Company;
        }
        if ($best === false || $required === null) {
            return $best;
        }

        return $best->rank() >= $required->rank() ? $best : false;
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

    /** Object access always combines the permission scope with tenant ownership. */
    public function canAccessEmployee(User $user, string $permission, Employee $target): bool
    {
        $me = $this->employee($user);
        $scope = $this->can($user, $permission);
        if ($me === null || $me->company_id === null || (int) $me->company_id !== (int) $target->company_id) {
            return false;
        }

        return match ($scope) {
            PermissionScope::Company => true,
            PermissionScope::Department => (int) $me->id === (int) $target->id
                || ($me->department_id !== null && (int) $me->department_id === (int) $target->department_id),
            PermissionScope::Own => (int) $me->id === (int) $target->id,
            default => false,
        };
    }

    public function forget(): void
    {
        $this->employeeCache = [];
        $this->roleNamesCache = [];
        $this->canCache = [];
        $this->effectiveCache = [];
    }

    public function scopeEmployees(Builder $query, User $user, string $permission): Builder
    {
        $me = $this->employee($user);
        $scope = $this->can($user, $permission);
        if ($me?->company_id === null || $scope === false) {
            return $query->whereRaw('1 = 0');
        }
        $query->where('company_id', $me->company_id);

        return match ($scope) {
            PermissionScope::Company => $query,
            PermissionScope::Department => $me->department_id !== null ? $query->where('department_id', $me->department_id) : $query->whereKey($me->id),
            default => $query->whereKey($me->id),
        };
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
