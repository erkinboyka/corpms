<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Finance\Services\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\FinAccount;
use App\Shared\Enums\FinAccountKind;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Касса и расчётные счета (Этап E3): справочник + балансы по журналу.
 */
class FinAccountController extends Controller
{
    public function __construct(
        protected FinanceService $finance,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Счета компании с балансами и последними операциями. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;

        return view('finance.accounts.index', [
            'accounts' => $this->finance->accountsWithBalance($companyId),
            'metrics' => $this->finance->metrics($companyId),
            'canManage' => $this->permissions->check($request->user(), 'finance.manage'),
            'kinds' => FinAccountKind::cases(),
        ]);
    }

    /** Создание счёта/кассы. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['name' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(FinAccountKind::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'opening_balance' => ['nullable', 'numeric', 'min:-999999999999', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'название', 'kind' => 'вид счёта']);

        $account = FinAccount::query()->create([
            'company_id' => $me->company_id,
            'name' => $validated['name'],
            'kind' => FinAccountKind::from($validated['kind']),
            'currency' => mb_strtoupper($validated['currency'] ?? 'RUB'),
            'opening_balance' => $validated['opening_balance'] ?? 0,
            'is_active' => true,
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->audit->log($request->user(), 'finance.account_created', 'fin_account', $account->id, null, [
            'name' => $account->name,
            'kind' => $account->kind->value,
        ]);

        return redirect()->route('finance.accounts.index')->with('status', 'Счёт «'.$account->name.'» создан');
    }

    /** Включение/выключение счёта (без удаления — история операций сохраняется). */
    public function toggle(Request $request, FinAccount $account): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($account, $me);

        $account->is_active = ! $account->is_active;
        $account->save();

        $this->audit->log($request->user(), 'finance.account_toggled', 'fin_account', $account->id, null, [
            'is_active' => $account->is_active,
        ]);

        return back()->with('status', 'Счёт «'.$account->name.'» '.($account->is_active ? 'включён' : 'выключен'));
    }
}
