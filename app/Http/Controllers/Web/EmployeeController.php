<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Position;
use App\Models\Role;
use App\Shared\Enums\EmployeeStatus;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Сотрудники (п.9–11 ТЗ): список с фильтрами, профиль, роли (HR-функции).
 */
class EmployeeController extends Controller
{
    public function __construct(
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $employee = $request->user()->employee;
        \assert($employee !== null);

        $scope = $this->permissions->can($request->user(), 'employees.view');

        $query = Employee::query()
            ->with(['user', 'department', 'position', 'roles'])
            // межтенантная изоляция (S2): база любого списка — компания сотрудника
            ->where('company_id', $employee->company_id);

        if ($scope === PermissionScope::Department) {
            $query->where('department_id', $employee->department_id);
        } elseif ($scope === PermissionScope::Own) {
            $query->whereKey($employee->id);
        }

        // фильтры
        $search = trim((string) $request->input('q'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%");
            });
        }

        if ($departmentId = $request->input('department_id')) {
            $query->where('department_id', (int) $departmentId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'active');
        }

        // справочники для фильтров/форм — только своей компании
        $departments = Department::query()
            ->where('company_id', $employee->company_id)
            ->orderBy('name')
            ->get();
        $positions = Position::query()
            ->where('company_id', $employee->company_id)
            ->orderBy('name')
            ->get();

        // пояс компании — для авто-выбора страны в поле телефона новичка
        $companyTimezone = $employee->company?->timezone;

        // пагинация вместо прежнего лимита-2000; фильтры сохраняются в ссылках пейджера
        $employees = $query->orderBy('last_name')->paginate(20)->withQueryString();

        $canManage = $this->permissions->check($request->user(), 'employees.manage');

        return view('employees.index', compact('employees', 'departments', 'positions', 'scope', 'search', 'canManage', 'companyTimezone'));
    }

    /** Приём нового сотрудника (п.9 ТЗ): HR завёл карточку; учётная запись подключается позже на странице «Пользователи». */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null && $me->company_id !== null);

        $validated = $request->validate([
            'employee_no' => ['required', 'string', 'max:32',
                Rule::unique('employees', 'employee_no')->where('company_id', $me->company_id)],
            'last_name' => ['required', 'string', 'max:128'],
            'first_name' => ['required', 'string', 'max:128'],
            'middle_name' => ['nullable', 'string', 'max:128'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'manager_id' => ['nullable', 'exists:employees,id'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9][\d\s()\-]{6,24}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'hired_at' => ['nullable', 'date'],
            'work_schedule_id' => ['nullable', 'exists:work_schedules,id'],
            'status' => ['required', 'in:active,inactive'],
        ], [
            'phone.regex' => 'Формат: международный номер, начинается с +7/+992/… (например +7 (999) 123-45-67).',
        ]);

        $validated['company_id'] = $me->company_id;

        // ссылки из формы — только записи своей компании (P0-9: анти-перенос в чужую организацию)
        foreach (['department_id' => 'departments', 'position_id' => 'positions', 'manager_id' => 'employees', 'work_schedule_id' => 'work_schedules'] as $field => $table) {
            if (($validated[$field] ?? null) !== null) {
                TenantGuard::ensureReference($table, (int) $validated[$field], $me);
            }
        }

        $employee = Employee::create($validated);

        $this->audit->log($request->user(), 'employee.created', 'employee', $employee->id, null, $validated);

        return back()->with('status', 'Сотрудник добавлен: '.$employee->last_name.' '.$employee->first_name);
    }

    public function show(Request $request, Employee $employee): View
    {
        TenantGuard::ensureEmployee($employee, $request->user()->employee);

        $employee->load(['user', 'department', 'position', 'roles', 'workSchedule', 'notifications']);

        $canManage = $this->permissions->check($request->user(), 'employees.manage');
        // реквизиты (email/телефон) — отдельное право employees.view_props (п.11 ТЗ)
        $canViewProps = $canManage || $this->permissions->check($request->user(), 'employees.view_props');

        // пояс компании — для авто-выбора страны в поле телефона
        $companyTimezone = $employee->company?->timezone;

        return view('employees.show', compact('employee', 'canManage', 'canViewProps', 'companyTimezone'));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensureEmployee($employee, $me);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:128'],
            'last_name' => ['required', 'string', 'max:128'],
            'middle_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9][\d\s()\-]{6,24}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'employee_no' => ['required', 'string', 'max:32'],
            // статусы согласованы с EmployeeStatus enum (active/inactive/dismissed);
            // ранее здесь был 'fired', которого нет в enum — увольнение падало с 422
            'status' => ['required', Rule::enum(EmployeeStatus::class)],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'work_schedule_id' => ['nullable', 'exists:work_schedules,id'],
        ], [
            'phone.regex' => 'Формат: международный номер, начинается с +7/+992/… (например +7 (999) 123-45-67).',
        ]);

        // справочники в поле ввода — только из компании текущего пользователя (анти-перенос)
        foreach (['department_id' => 'departments', 'position_id' => 'positions', 'work_schedule_id' => 'work_schedules'] as $field => $table) {
            if (($validated[$field] ?? null) !== null) {
                TenantGuard::ensureReference($table, (int) $validated[$field], $me);
            }
        }

        $old = $employee->only(array_keys($validated));
        $employee->update($validated);

        $this->audit->log($request->user(), 'employee.updated', 'employee', $employee->id, $old, $validated);

        return redirect()->route('employees.show', $employee)->with('status', 'Профиль обновлён');
    }

    /** Роли сотрудника (п.9, 50: управление ролями). */
    public function updateRoles(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        TenantGuard::ensureEmployee($employee, $user->employee);

        $validated = $request->validate([
            'role_ids' => ['array'],
            'role_ids.*' => ['exists:roles,id'],
        ]);

        $requestedIds = array_map('intval', $validated['role_ids'] ?? []);
        $requestedIds = array_values(array_unique($requestedIds));

        // --- Анти-эскалация (S-1) ---
        // 1) super_admin может назначать super_admin; никто другой — нет.
        // 2) Нельзя выдать роль, которой у самого себя нет (нельзя повысить
        //    себя/другого выше собственного уровня).
        $actorEmployee = $user->employee;
        $actorRoleIds = $actorEmployee !== null
            ? EmployeeRole::query()->where('employee_id', $actorEmployee->id)->pluck('role_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $isSuperAdmin = $this->permissions->roleNames($user)->contains('super_admin');

        $grantableIds = Role::query()->pluck('id', 'slug');
        $allowedRoleIds = [];

        foreach ($grantableIds as $slug => $roleId) {
            $roleId = (int) $roleId;

            if ($slug === 'super_admin' && ! $isSuperAdmin) {
                continue; // только super_admin выдаёт super_admin
            }

            if (! $isSuperAdmin && ! in_array($roleId, $actorRoleIds, true)) {
                continue; // выдать можно только роль, которая есть у тебя самого
            }

            $allowedRoleIds[] = $roleId;
        }

        $blocked = array_diff($requestedIds, $allowedRoleIds);
        if ($blocked !== []) {
            return back()->withErrors([
                'role_ids' => 'Недостаточно прав для назначения одной из выбранных ролей (эскалация запрещена).',
            ]);
        }

        $old = $employee->roles()->pluck('role_id')->all();

        // replace партициями (без переизобретения реализации)
        EmployeeRole::query()->where('employee_id', $employee->id)->delete();

        foreach ($requestedIds as $roleId) {
            EmployeeRole::create(['employee_id' => $employee->id, 'role_id' => $roleId]);
        }

        $new = $employee->roles()->pluck('role_id')->all();

        $this->audit->log($request->user(), 'employee.roles_changed', 'employee', $employee->id, ['roles' => $old], ['roles' => $new]);

        return back()->with('status', 'Роли обновлены');
    }

    /** Организационная карусель подчиненности: диаграммы строит view. */
    public function subordinates(Request $request, Employee $employee): View
    {
        TenantGuard::ensureEmployee($employee, $request->user()->employee);

        $direct = Employee::query()
            ->with(['department', 'position'])
            ->where('manager_id', $employee->id)
            ->orderBy('last_name')
            ->get();

        return view('employees.subordinates', compact('employee', 'direct'));
    }
}
