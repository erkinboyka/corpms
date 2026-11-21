<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Структура компании (п.5–8 ТЗ): подразделения, дерево/орг-чарт, должности.
 */
class DepartmentController extends Controller
{
    public function __construct(
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $employee = $request->user()->employee;
        \assert($employee !== null);

        $scope = $this->permissions->can($request->user(), 'departments.view');

        $departments = Department::query()
            ->with(['company', 'director', 'deputy'])
            ->withCount('activeEmployees')
            // межтенантная изоляция (P0): база списка — компания сотрудника
            ->where('company_id', $employee->company_id)
            ->when($scope !== PermissionScope::Company, fn ($q) => $q->whereKey($employee->department_id))
            ->orderBy('name')
            ->get();

        // активные сотрудники активных компаний одним запросом — для select'ов руководителей без N+1 в цикле
        $companyIds = $departments->pluck('company_id')->unique();
        $employeesByCompany = Employee::query()
            ->whereIn('company_id', $companyIds)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get()
            ->groupBy('company_id');

        return view('departments.index', compact('departments', 'scope', 'employeesByCompany'));
    }

    public function orgChart(Request $request): View
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }

        // орг-чарт рисует дерево своей компании, а не все тенанты сразу (P0)
        $companies = Company::query()->whereKey($me->company_id)->orderBy('id')->get();
        $tree = Department::query()
            ->where('company_id', $me->company_id)
            ->with(['director', 'company'])
            ->with(['activeEmployees' => fn ($q) => $q->orderBy('last_name')->orderBy('first_name')->with('position')])
            ->withCount('activeEmployees')
            ->orderBy('name')
            ->get();

        return view('departments.orgchart', compact('companies', 'tree'));
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        \assert($employee !== null);
        \assert($employee->company_id !== null);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:32', 'unique:departments,code'],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'director_id' => ['nullable', 'exists:employees,id'],
            'deputy_id' => ['nullable', 'exists:employees,id'],
        ]);

        $validated['company_id'] = $employee->company_id;

        // ссылки из тела запроса — только в рамках своей компании (P0)
        if (($validated['parent_id'] ?? null) !== null) {
            TenantGuard::ensureReference('departments', (int) $validated['parent_id'], $employee, __('Родительский отдел принадлежит другой компании'));
        }
        foreach (['director_id', 'deputy_id'] as $refField) {
            if (($validated[$refField] ?? null) !== null) {
                TenantGuard::ensureReference('employees', (int) $validated[$refField], $employee, __('Сотрудник принадлежит другой компании'));
            }
        }

        $department = Department::create($validated);

        $this->audit->log($request->user(), 'department.created', 'department', $department->id, null, $validated);

        return back()->with('status', __('Подразделение создано'));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'director_id' => ['nullable', 'exists:employees,id'],
            'deputy_id' => ['nullable', 'exists:employees,id'],
        ]);

        // ссылки из тела запроса — только в рамках своей компании (P0)
        if (($validated['parent_id'] ?? null) !== null) {
            TenantGuard::ensureReference('departments', (int) $validated['parent_id'], $me, __('Родительский отдел принадлежит другой компании'));
        }
        foreach (['director_id', 'deputy_id'] as $refField) {
            if (($validated[$refField] ?? null) !== null) {
                TenantGuard::ensureReference('employees', (int) $validated[$refField], $me, __('Сотрудник принадлежит другой компании'));
            }
        }

        $old = ['parent_id' => $department->parent_id, 'director_id' => $department->director_id, 'deputy_id' => $department->deputy_id];

        // изменение места в оргструктуре — отдельное право structure.manage (departments.manage гоняет реквизиты)
        if (isset($validated['parent_id']) && (int) ($validated['parent_id'] ?? 0) !== (int) ($old['parent_id'] ?? 0)
            && $this->permissions->check($request->user(), 'structure.manage') === false) {
            abort(403, __('Перемещение отдела в структуре требует права structure.manage'));
        }

        $department->update($validated);

        $this->audit->log($request->user(), 'department.updated', 'department', $department->id, $old, $validated);

        return back()->with('status', __('Подразделение обновлено'));
    }

    /** Отдел удаляем только пустым: без дочерних узлов и без сотрудников. */
    public function destroy(Request $request, Department $department): RedirectResponse
    {
        if (Department::query()->where('parent_id', $department->id)->exists()) {
            abort(403, __('Нельзя удалить отдел: он имеет дочерние подразделения'));
        }

        if (Employee::query()->where('department_id', $department->id)->exists()) {
            abort(403, __('Нельзя удалить отдел: в нём числятся сотрудники'));
        }

        $old = $department->only(['name', 'code', 'parent_id']);

        $department->delete();

        $this->audit->log($request->user(), 'department.deleted', 'department', $department->id, $old, null);

        return back()->with('status', __('Подразделение удалено'));
    }
}
