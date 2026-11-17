<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Finance\Services\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\FinAccount;
use App\Models\FinCategory;
use App\Models\FinTransaction;
use App\Shared\Enums\FinDirection;
use App\Shared\Enums\FinTransactionSource;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Доходы и расходы (Этап E3): журнал операций по счетам и статьям.
 *
 * Баланс счёта не хранится — он считается как opening_balance + приход − расход,
 * поэтому любая ручная операция сразу видна в кассе и метриках месяца.
 */
class FinTransactionController extends Controller
{
    public function __construct(
        protected FinanceService $finance,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Журнал операций за месяц с фильтрами. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        [$start, $end] = [
            $month.'-01',
            CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString(),
        ];
        $direction = (string) $request->query('direction');
        $accountId = $request->query('account');

        $transactions = FinTransaction::query()
            ->where('company_id', $companyId)
            ->with(['account', 'category', 'creator'])
            ->whereBetween('occurred_at', [$start, $end])
            ->when(in_array($direction, ['income', 'expense'], true), static fn ($query) => $query->where('direction', $direction))
            ->when(is_numeric($accountId), static fn ($query) => $query->where('account_id', (int) $accountId))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('finance.transactions.index', [
            'transactions' => $transactions,
            'month' => $month,
            'direction' => $direction,
            'accountFilter' => $accountId,
            'metrics' => $this->finance->metrics($companyId, $month),
            'accounts' => FinAccount::query()->where('company_id', $companyId)->active()->orderBy('name')->get(),
            'categories' => FinCategory::query()->where('company_id', $companyId)->active()->orderBy('name')->get(),
            'directions' => FinDirection::cases(),
            'canManage' => $this->permissions->check($request->user(), 'finance.manage'),
        ]);
    }

    /** Ручная операция дохода/расхода. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['amount' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'direction' => ['required', Rule::enum(FinDirection::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'occurred_at' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'сумма', 'direction' => 'направление', 'occurred_at' => 'дата']);

        TenantGuard::ensureReference('fin_accounts', (int) $validated['account_id'], $me);

        $categoryId = $validated['category_id'] ?? null;

        if ($categoryId !== null && $categoryId !== '') {
            TenantGuard::ensureReference('fin_categories', (int) $categoryId, $me);

            $category = FinCategory::query()->find((int) $categoryId);

            if ($category !== null && $category->direction->value !== $validated['direction']) {
                return back()->withErrors(['category_id' => 'Статья относится к другому направлению'])->withInput();
            }
        }

        $transaction = FinTransaction::query()->create([
            'company_id' => $me->company_id,
            'account_id' => (int) $validated['account_id'],
            'category_id' => $categoryId ?: null,
            'direction' => FinDirection::from($validated['direction']),
            'amount' => $validated['amount'],
            'occurred_at' => $validated['occurred_at'],
            'description' => $validated['description'] ?? null,
            'source' => FinTransactionSource::Manual,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'finance.transaction_created', 'fin_transaction', $transaction->id, null, [
            'direction' => $transaction->direction->value,
            'amount' => (string) $transaction->amount,
        ]);

        return back()->with('status', 'Операция на сумму '.number_format((float) $transaction->amount, 2, ',', ' ').' записана');
    }
}
