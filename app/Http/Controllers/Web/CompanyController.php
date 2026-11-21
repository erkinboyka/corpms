<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Position;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Компании (п.5 ТЗ) и должности (п.8 ТЗ).
 */
class CompanyController extends Controller
{
    public function __construct(
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        // справочник тенант-существ: целиком видит только платформенная роль
        // super_admin (единственная с companies.manage); остальные роли —
        // только свою компанию (компании-тенанты не должны утекать между ролями)
        $isPlatformAdmin = $this->permissions->roleNames($request->user())->contains('super_admin');
        $me = $this->permissions->employee($request->user());

        $companies = Company::query()
            ->withCount('departments')->withCount('employees')
            ->when($isPlatformAdmin === false, fn ($q) => $q->whereKey($me?->company_id))
            ->orderBy('name')
            ->get();

        return view('companies.index', compact('companies'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'unique:companies,code'],
            // ИНН: ровно 10 (юрлицо) или 12 (физлицо/ИП) цифр
            'inn' => ['nullable', 'string', 'regex:/^\d{10}$|^\d{12}$/'],
            'region' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9][\d\s()\-]{6,24}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'inn.regex' => __('ИНН — 10 цифр (компания) или 12 цифр (ИП).'),
            'phone.regex' => __('Формат: международный номер, начинается с +7/+992/… (например +7 (999) 123-45-67).'),
            'timezone.timezone' => __('Выберите часовой пояс из списка.'),
        ]);

        $company = Company::create($validated);

        return back()->with('status', __('Компания создана'));
    }

    /* ---------------- должности ---------------- */

    public function positionsIndex(Request $request): View
    {
        $employee = $request->user()->employee;
        \assert($employee !== null);

        // межтенантная изоляция (P0): должности только своей компании
        $positions = Position::query()
            ->with('company')
            ->where('company_id', $employee->company_id)
            ->orderBy('name')
            ->get();

        return view('positions.index', compact('positions'));
    }

    public function positionsStore(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        \assert($employee !== null);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:32', 'unique:positions,code'],
            'grade' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['company_id'] = $employee->company_id;

        Position::create($validated);

        return back()->with('status', __('Должность добавлена'));
    }

    /* ---------------- управление ---------------- */

    public function update(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('companies', 'code')->ignore($company->id)],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'regex:/^\d{10}$|^\d{12}$/'],
            'region' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9][\d\s()\-]{6,24}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'inn.regex' => __('ИНН — 10 цифр (компания) или 12 цифр (ИП).'),
            'phone.regex' => __('Формат: международный номер, начинается с +7/+992/… (например +7 (999) 123-45-67).'),
            'timezone.timezone' => __('Выберите часовой пояс из списка.'),
        ]);

        $keys = ['name', 'code', 'legal_name', 'address', 'inn', 'region', 'timezone', 'phone', 'email', 'is_active'];
        $old = $company->only($keys);

        $company->update($validated);

        $this->audit->log($request->user(), 'company.updated', 'company', $company->id, $old, $validated);

        return back()->with('status', __('Компания обновлена'));
    }

    /** Удаление допускается только для пустой компании — без отделов и сотрудников. */
    public function destroy(Request $request, Company $company): RedirectResponse
    {
        if ($company->departments()->exists() || $company->employees()->exists()) {
            abort(403, __('Нельзя удалить компанию: в ней есть отделы или сотрудники'));
        }

        $old = $company->only(['name', 'code']);

        $company->delete();

        $this->audit->log($request->user(), 'company.deleted', 'company', $company->id, $old, null);

        return back()->with('status', __('Компания удалена'));
    }

    public function positionsUpdate(Request $request, Position $position): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:32',
                Rule::unique('positions', 'code')->ignore($position->id)->where('company_id', $position->company_id)],
            'grade' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $old = $position->only(['name', 'code', 'grade', 'description']);

        $position->update($validated);

        $this->audit->log($request->user(), 'position.updated', 'position', $position->id, $old, $validated);

        return back()->with('status', __('Должность обновлена'));
    }

    /** Должность с закреплёнными сотрудниками удалить нельзя (см. ссылочную целостность HR-карточек). */
    public function positionsDestroy(Request $request, Position $position): RedirectResponse
    {
        if ($position->employees()->exists()) {
            abort(403, __('Нельзя удалить должность: за ней закреплены сотрудники'));
        }

        $old = $position->only(['name', 'code']);

        $position->delete();

        $this->audit->log($request->user(), 'position.deleted', 'position', $position->id, $old, null);

        return back()->with('status', __('Должность удалена'));
    }
}
