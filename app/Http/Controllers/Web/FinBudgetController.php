<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Finance\Services\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\FinBudget;
use App\Models\FinCategory;
use App\Shared\Enums\FinDirection;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Бюджеты (Этап E3): план на месяц по статье, факт считается по журналу операций.
 *
 * Повторное сохранение той же статьи/направления в том же месяце обновляет план,
 * а не создаёт дубль (query-builder upsert по company + period + direction + category).
 */
class FinBudgetController extends Controller
{
    public function __construct(
        protected FinanceService $finance,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** План/факт бюджета за месяц. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        $budgets = $this->finance->budgetsWithActual($companyId, $month);

        return view('finance.budgets.index', [
            'budgets' => $budgets,
            'month' => $month,
            'metrics' => $this->finance->metrics($companyId, $month),
            'categories' => FinCategory::query()
                ->where('company_id', $companyId)
                ->active()
                ->orderBy('direction')
                ->orderBy('name')
                ->get(),
            'directions' => FinDirection::cases(),
            'canManage' => $this->permissions->check($request->user(), 'finance.manage'),
        ]);
    }

    /** Сохранение плана (upsert по месяцу/направлению/статье). */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['planned_amount' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'direction' => ['required', Rule::enum(FinDirection::class)],
            'category_id' => ['nullable', 'integer'],
            'planned_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['planned_amount' => 'план', 'direction' => 'направление']);

        $categoryId = $validated['category_id'] ?? null;

        if ($categoryId !== null && $categoryId !== '') {
            TenantGuard::ensureReference('fin_categories', (int) $categoryId, $me);

            $category = FinCategory::query()->find((int) $categoryId);

            if ($category !== null && $category->direction->value !== $validated['direction']) {
                return back()->withErrors(['category_id' => 'Статья относится к другому направлению'])->withInput();
            }
        }

        $period = $validated['month'].'-01';

        $query = FinBudget::query()
            ->where('company_id', $me->company_id)
            ->whereDate('period', $period)
            ->where('direction', $validated['direction']);

        if ($categoryId === null || $categoryId === '') {
            $query->whereNull('category_id');
        } else {
            $query->where('category_id', (int) $categoryId);
        }

        $budget = $query->first() ?? new FinBudget([
            'company_id' => $me->company_id,
            'period' => $period,
            'direction' => FinDirection::from($validated['direction']),
            'category_id' => $categoryId ?: null,
        ]);

        $budget->planned_amount = $validated['planned_amount'];
        $budget->notes = $validated['notes'] ?? null;
        $budget->save();

        $this->audit->log($request->user(), 'finance.budget_saved', 'fin_budget', $budget->id, null, [
            'period' => $period,
            'direction' => $budget->direction->value,
            'planned_amount' => (string) $budget->planned_amount,
        ]);

        return redirect()
            ->route('finance.budgets.index', ['month' => $validated['month']])
            ->with('status', 'Бюджет на '.$validated['month'].' сохранён');
    }
}
