<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\FinCategory;
use App\Shared\Enums\FinDirection;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Статьи доходов и расходов (Этап E3): аналитика операций и бюджетов.
 */
class FinCategoryController extends Controller
{
    public function __construct(
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Справочник статей по направлениям. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;

        $categories = FinCategory::query()
            ->where('company_id', $companyId)
            ->orderBy('direction')
            ->orderBy('name')
            ->get();

        return view('finance.categories.index', [
            'categories' => $categories,
            'directions' => FinDirection::cases(),
            'canManage' => $this->permissions->check($request->user(), 'finance.manage'),
        ]);
    }

    /** Создание статьи. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['name' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::enum(FinDirection::class)],
        ], [], ['name' => 'название', 'direction' => 'направление']);

        $exists = FinCategory::query()
            ->where('company_id', $me->company_id)
            ->where('direction', $validated['direction'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['name' => 'Статья с таким названием уже есть'])->withInput();
        }

        $category = FinCategory::query()->create([
            'company_id' => $me->company_id,
            'name' => $validated['name'],
            'direction' => FinDirection::from($validated['direction']),
            'is_active' => true,
        ]);

        $this->audit->log($request->user(), 'finance.category_created', 'fin_category', $category->id, null, [
            'name' => $category->name,
            'direction' => $category->direction->value,
        ]);

        return back()->with('status', 'Статья «'.$category->name.'» создана');
    }

    /** Включение/выключение статьи. */
    public function toggle(Request $request, FinCategory $category): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($category, $me);

        $category->is_active = ! $category->is_active;
        $category->save();

        $this->audit->log($request->user(), 'finance.category_toggled', 'fin_category', $category->id, null, [
            'is_active' => $category->is_active,
        ]);

        return back()->with('status', 'Статья «'.$category->name.'» '.($category->is_active ? 'включена' : 'выключена'));
    }
}
