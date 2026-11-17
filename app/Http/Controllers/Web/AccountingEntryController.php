<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Accounting\Services\AccountingService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\AccEntry;
use App\Models\AccEntryLine;
use App\Models\FinTransaction;
use App\Shared\Enums\AccEntrySource;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use LogicException;

/**
 * Журнал проводок (Этап E4): ручные сбалансированные проводки и синк из финансов.
 */
class AccountingEntryController extends Controller
{
    public function __construct(
        protected AccountingService $accounting,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Журнал проводок за период. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        $from = $month.'-01';
        $to = CarbonImmutable::createFromFormat('Y-m-d', $from)->endOfMonth()->toDateString();

        $entries = AccEntry::query()
            ->where('company_id', $companyId)
            ->whereBetween('entry_date', [$from, $to])
            ->with(['lines.account', 'creator'])
            ->withCount('lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $withoutEntry = FinTransaction::query()
            ->where('company_id', $companyId)
            ->whereNotIn('id', AccEntry::query()
                ->where('company_id', $companyId)
                ->whereNotNull('finance_transaction_id')
                ->select('finance_transaction_id'))
            ->whereBetween('occurred_at', [$from, $to])
            ->count();

        return view('accounting.entries.index', [
            'entries' => $entries,
            'month' => $month,
            'accounts' => $this->accounting->chart($companyId)->where('is_active', true)->values(),
            'withoutEntry' => $withoutEntry,
            'financePosted' => AccEntry::query()
                ->where('company_id', $companyId)
                ->whereBetween('entry_date', [$from, $to])
                ->where('source', AccEntrySource::Finance->value)
                ->count(),
            'turnover' => (float) AccEntryLine::query()
                ->join('acc_entries', 'acc_entries.id', '=', 'acc_entry_lines.entry_id')
                ->where('acc_entries.company_id', $companyId)
                ->whereBetween('acc_entries.entry_date', [$from, $to])
                ->sum('acc_entry_lines.debit'),
            'canManage' => $this->permissions->check($request->user(), 'accounting.manage'),
        ]);
    }

    /** Проводка: строки и баланс проверяет доменный сервис. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['entry_date' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ], [], ['entry_date' => 'дата', 'lines' => 'строки проводки']);

        /** @var Collection<int, array<string, mixed>> $filled */
        $filled = collect($validated['lines'])
            ->filter(static fn (array $line): bool => (float) ($line['debit'] ?? 0) > 0 || (float) ($line['credit'] ?? 0) > 0);

        if ($filled->isEmpty()) {
            return back()->withErrors(['lines' => 'Заполните хотя бы две строки проводки'])->withInput();
        }

        foreach ($filled as $line) {
            if (empty($line['account_id'])) {
                return back()->withErrors(['lines' => 'Для каждой суммы укажите счёт'])->withInput();
            }

            TenantGuard::ensureReference('acc_accounts', (int) $line['account_id'], $me);
        }

        try {
            $entry = $this->accounting->createEntry(
                (int) $me->company_id,
                ['entry_date' => $validated['entry_date'], 'description' => $validated['description'] ?? null],
                $filled->values()->all(),
                $me->user_id,
            );
        } catch (LogicException $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        $this->audit->log($request->user(), 'accounting.entry_created', 'acc_entry', $entry->id, null, [
            'amount' => (string) $entry->amount(),
            'date' => $validated['entry_date'],
        ]);

        return redirect()->route('accounting.entries.show', $entry)->with('status', 'Проводка №'.$entry->id.' создана');
    }

    /** Карточка проводки со строками. */
    public function show(Request $request, AccEntry $entry): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($entry, $me);

        $entry->load(['lines.account', 'creator', 'financeTransaction']);

        return view('accounting.entries.show', [
            'entry' => $entry,
            'debitTotal' => $entry->debitTotal(),
            'creditTotal' => $entry->creditTotal(),
        ]);
    }

    /** Синк проводок из финансов за месяц. */
    public function sync(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $month = (string) $request->input('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        $from = $month.'-01';
        $to = CarbonImmutable::createFromFormat('Y-m-d', $from)->endOfMonth()->toDateString();

        $created = $this->accounting->syncFinance((int) $me->company_id, $from, $to);

        $this->audit->log($request->user(), 'accounting.finance_synced', 'acc_entry', null, null, [
            'month' => $month,
            'created' => $created,
        ]);

        return back()->with('status', 'Проведено операций из финансов: '.$created);
    }
}
