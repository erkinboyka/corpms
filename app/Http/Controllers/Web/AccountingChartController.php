<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Accounting\Services\AccountingService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\AccAccount;
use App\Shared\Enums\AccAccountKind;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * План счетов (Этап E4): справочник счетов и типовой план для компании.
 */
class AccountingChartController extends Controller
{
    public function __construct(
        protected AccountingService $accounting,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** План счетов компании. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $accounts = $this->accounting->chart($companyId);

        return view('accounting.chart.index', [
            'accounts' => $accounts,
            'kinds' => AccAccountKind::cases(),
            'canManage' => $this->permissions->check($request->user(), 'accounting.manage'),
            'totals' => [
                'accounts' => $accounts->count(),
                'assets' => $accounts->where('kind', AccAccountKind::Asset)->count(),
                'income' => $accounts->where('kind', AccAccountKind::Income)->count(),
                'expense' => $accounts->where('kind', AccAccountKind::Expense)->count(),
            ],
        ]);
    }

    /** Добавление счёта в план. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['code' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:10',
                Rule::unique('acc_accounts', 'code')->where('company_id', $me->company_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(AccAccountKind::class)],
        ], [], ['code' => 'код', 'name' => 'название', 'kind' => 'вид счёта']);

        $account = AccAccount::query()->create([
            'company_id' => $me->company_id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'kind' => AccAccountKind::from($validated['kind']),
            'is_active' => true,
        ]);

        $this->audit->log($request->user(), 'accounting.account_created', 'acc_account', $account->id, null, [
            'code' => $account->code,
            'name' => $account->name,
        ]);

        return back()->with('status', 'Счёт '.$account->code.' добавлен в план');
    }

    /** Заполнение типового плана счетов. */
    public function seedDefault(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $created = $this->accounting->ensureDefaultChart((int) $me->company_id);

        $this->audit->log($request->user(), 'accounting.chart_seeded', 'acc_account', null, null, [
            'created' => $created,
        ]);

        return back()->with(
            'status',
            $created > 0
                ? 'Типовой план счетов добавлен: '.$created.' счетов'
                : 'Типовой план уже заполнен — недостающих счетов нет',
        );
    }

    /** Включение/выключение счёта (история проводок сохраняется). */
    public function toggle(Request $request, AccAccount $account): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($account, $me);

        $account->is_active = ! $account->is_active;
        $account->save();

        $this->audit->log($request->user(), 'accounting.account_toggled', 'acc_account', $account->id, null, [
            'is_active' => $account->is_active,
        ]);

        return back()->with('status', 'Счёт '.$account->code.' '.($account->is_active ? 'включён' : 'выключен'));
    }
}
