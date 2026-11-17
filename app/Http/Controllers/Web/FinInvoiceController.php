<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Finance\Services\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\CrmClient;
use App\Models\FinAccount;
use App\Models\FinCategory;
use App\Models\FinInvoice;
use App\Shared\Enums\FinInvoiceKind;
use App\Shared\Enums\FinInvoiceStatus;
use App\Shared\Enums\FinPaymentMethod;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;

/**
 * Счета-фактуры и акты (Этап E3): выставление, оплата, отмена.
 *
 * Оплата — отдельный POST: создаёт платёж и приход в журнале, закрывает счёт
 * при полной сумме. Переплата и оплата черновика/закрытого счёта отклоняются.
 */
class FinInvoiceController extends Controller
{
    public function __construct(
        protected FinanceService $finance,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Реестр счетов и актов с фильтрами. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $status = (string) $request->query('status', 'open');
        $kind = (string) $request->query('kind');
        $search = trim((string) $request->query('q'));

        $allowed = ['open', 'draft', 'issued', 'paid', 'cancelled', 'all'];

        if (in_array($status, $allowed, true) === false) {
            $status = 'open';
        }

        $invoices = FinInvoice::query()
            ->where('company_id', $companyId)
            ->with(['client'])
            ->withSum('payments', 'amount')
            ->when($status === 'open', static fn ($query) => $query->open())
            ->when($status !== 'open' && $status !== 'all', static fn ($query) => $query->where('status', $status))
            ->when(in_array($kind, ['invoice', 'act'], true), static fn ($query) => $query->where('kind', $kind))
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(static function ($sub) use ($search): void {
                    $sub->where('number', 'like', '%'.$search.'%')
                        ->orWhere('counterparty_name', 'like', '%'.$search.'%')
                        ->orWhereHas('client', static fn ($client) => $client->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('finance.invoices.index', [
            'invoices' => $invoices,
            'status' => $status,
            'kind' => $kind,
            'search' => $search,
            'metrics' => $this->finance->metrics($companyId),
            'clients' => $this->clients($companyId),
            'kinds' => FinInvoiceKind::cases(),
            'canManage' => $this->permissions->check($request->user(), 'finance.invoices.manage'),
        ]);
    }

    /** Карточка счёта: платежи и форма оплаты. */
    public function show(Request $request, FinInvoice $invoice): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($invoice, $me);

        $invoice->load(['client', 'creator', 'payments.account', 'payments.creator']);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
            'paid' => $invoice->paidAmount(),
            'remaining' => $invoice->remainingAmount(),
            'accounts' => FinAccount::query()->where('company_id', $me->company_id)->active()->orderBy('name')->get(),
            'categories' => FinCategory::query()
                ->where('company_id', $me->company_id)
                ->where('direction', 'income')
                ->active()
                ->orderBy('name')
                ->get(),
            'methods' => FinPaymentMethod::cases(),
            'canManage' => $this->permissions->check($request->user(), 'finance.invoices.manage'),
        ]);
    }

    /** Создание счёта/акта (черновик). */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['number' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'number' => [
                'required', 'string', 'max:32',
                Rule::unique('fin_invoices', 'number')->where('company_id', $me->company_id),
            ],
            'client_id' => ['nullable', 'integer'],
            'counterparty_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(FinInvoiceKind::class)],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['number' => 'номер', 'amount' => 'сумма', 'issue_date' => 'дата']);

        $clientId = $validated['client_id'] ?? null;

        if ($clientId !== null && $clientId !== '') {
            TenantGuard::ensureReference('crm_clients', (int) $clientId, $me);
        }

        $invoice = FinInvoice::query()->create([
            'company_id' => $me->company_id,
            'client_id' => $clientId ?: null,
            'counterparty_name' => $validated['counterparty_name'] ?? null,
            'number' => $validated['number'],
            'kind' => FinInvoiceKind::from($validated['kind']),
            'status' => FinInvoiceStatus::Draft,
            'issue_date' => $validated['issue_date'],
            'due_date' => $validated['due_date'] ?? null,
            'amount' => $validated['amount'],
            'vat_rate' => $validated['vat_rate'] ?? 0,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'finance.invoice_created', 'fin_invoice', $invoice->id, null, [
            'number' => $invoice->number,
            'amount' => (string) $invoice->amount,
        ]);

        return redirect()->route('finance.invoices.show', $invoice)->with('status', 'Счёт '.$invoice->number.' создан');
    }

    /** Выставление счёта (draft → issued). */
    public function issue(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($invoice, $me);

        try {
            $this->finance->issue($invoice);
        } catch (LogicException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $this->audit->log($request->user(), 'finance.invoice_issued', 'fin_invoice', $invoice->id, null, [
            'number' => $invoice->number,
        ]);

        return back()->with('status', 'Счёт '.$invoice->number.' выставлен');
    }

    /** Отмена счёта без платежей. */
    public function cancel(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($invoice, $me);

        try {
            $this->finance->cancel($invoice);
        } catch (LogicException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $this->audit->log($request->user(), 'finance.invoice_cancelled', 'fin_invoice', $invoice->id, null, [
            'number' => $invoice->number,
        ]);

        return back()->with('status', 'Счёт '.$invoice->number.' отменён');
    }

    /** Оплата счёта: платёж + приход в журнале. */
    public function storePayment(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($invoice, $me);

        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'paid_at' => ['required', 'date'],
            'method' => ['required', Rule::enum(FinPaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['account_id' => 'счёт', 'amount' => 'сумма']);

        TenantGuard::ensureReference('fin_accounts', (int) $validated['account_id'], $me);

        $categoryId = $validated['category_id'] ?? null;

        if ($categoryId !== null && $categoryId !== '') {
            TenantGuard::ensureReference('fin_categories', (int) $categoryId, $me);
        }

        try {
            $payment = $this->finance->registerPayment($invoice, [
                ...$validated,
                'category_id' => $categoryId ?: null,
            ], $me->user_id);
        } catch (LogicException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        $this->audit->log($request->user(), 'finance.payment_created', 'fin_payment', $payment->id, null, [
            'invoice_id' => $invoice->id,
            'amount' => (string) $payment->amount,
        ]);

        return back()->with('status', 'Платёж на '.number_format((float) $payment->amount, 2, ',', ' ').' принят');
    }

    /** @return Collection<int, CrmClient> */
    private function clients(int $companyId): Collection
    {
        return CrmClient::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->limit(300)
            ->get();
    }
}
