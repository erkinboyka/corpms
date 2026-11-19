<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Models\FinAccount;
use App\Models\FinBudget;
use App\Models\FinCategory;
use App\Models\FinInvoice;
use App\Models\FinPayment;
use App\Models\FinTransaction;
use App\Shared\Enums\FinDirection;
use App\Shared\Enums\FinInvoiceStatus;
use App\Shared\Enums\FinTransactionSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Финансовый движок (Этап E3): касса, доходы/расходы, счета и платежи, бюджеты.
 *
 * Правила:
 *   - баланс счёта = opening_balance + приход − расход журнала (не хранится);
 *   - оплата счёта атомарна: платёж + приход в журнале + статус paid, когда
 *     закрыта вся сумма; переплата и оплата невыставленного счёта отклоняются;
 *   - бюджет сравнивается с фактическими операциями месяца по статье.
 */
class FinanceService
{
    /**
     * Счета компании с рассчитанным балансом (атрибут balance).
     *
     * @return Collection<int, FinAccount>
     */
    public function accountsWithBalance(int $companyId): Collection
    {
        $deltas = $this->deltasByAccount($companyId);

        return FinAccount::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->each(function (FinAccount $account) use ($deltas): void {
                $account->setAttribute(
                    'balance',
                    (float) $account->opening_balance + (float) ($deltas[$account->id] ?? 0),
                );
            });
    }

    public function balance(FinAccount $account): float
    {
        return (float) $account->opening_balance + (float) ($this->deltasByAccount((int) $account->company_id)[$account->id] ?? 0);
    }

    /**
     * Метрики финансов за месяц (по умолчанию — текущий).
     *
     * Monetary totals are null when currencies differ; never imply an exchange rate.
     * Legacy invoices/budgets have no currency field and cannot be assigned one in a mixed ledger.
     *
     * @return array<string, mixed>
     */
    public function metrics(int $companyId, ?string $month = null): array
    {
        [$start, $end] = $this->periodBounds($month);

        $accounts = $this->accountsWithBalance($companyId);
        $flows = FinTransaction::query()
            ->where('company_id', $companyId)
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw('account_id, direction, SUM(amount) as total')
            ->groupBy('account_id', 'direction')->get();
        $byCurrency = [];
        foreach ($accounts->groupBy(fn (FinAccount $account): string => strtoupper(trim($account->currency))) as $currency => $group) {
            $accountFlows = $flows->whereIn('account_id', $group->modelKeys());
            $income = (float) $accountFlows->where('direction', FinDirection::Income)->sum('total');
            $expense = (float) $accountFlows->where('direction', FinDirection::Expense)->sum('total');
            $byCurrency[$currency] = ['balance' => (float) $group->sum('balance'), 'income' => $income,
                'expense' => $expense, 'profit' => $income - $expense];
        }
        ksort($byCurrency);
        $mixed = count($byCurrency) > 1;
        $single = $byCurrency === [] ? ['balance' => 0.0, 'income' => 0.0, 'expense' => 0.0, 'profit' => 0.0] : reset($byCurrency);

        $openInvoices = FinInvoice::query()
            ->where('company_id', $companyId)
            ->open()
            ->withSum('payments', 'amount')
            ->get();

        $unpaid = $openInvoices->sum(
            static fn (FinInvoice $invoice): float => max(0.0, (float) $invoice->amount - (float) $invoice->payments_sum_amount),
        );

        $overdue = $openInvoices->filter(static fn (FinInvoice $invoice): bool => $invoice->isOverdue())->count();

        return [
            'currency' => count($byCurrency) === 1 ? array_key_first($byCurrency) : null,
            'mixed_currencies' => $mixed,
            'by_currency' => $byCurrency,
            'balance' => $mixed ? null : $single['balance'],
            'income' => $mixed ? null : $single['income'],
            'expense' => $mixed ? null : $single['expense'],
            'profit' => $mixed ? null : $single['profit'],
            'unpaid' => $mixed ? null : (float) $unpaid,
            'invoices_open' => $openInvoices->count(),
            'invoices_overdue' => $overdue,
            'budget_planned' => $mixed ? null : (float) FinBudget::query()
                ->where('company_id', $companyId)
                ->whereBetween('period', [$start, $end])
                ->sum('planned_amount'),
        ];
    }

    /**
     * Выставление счёта: draft → issued.
     */
    public function issue(FinInvoice $invoice): FinInvoice
    {
        return DB::transaction(function () use ($invoice): FinInvoice {
            $invoice = FinInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== FinInvoiceStatus::Draft) {
                throw new LogicException('Выставить можно только черновик счёта');
            }

            if ((float) $invoice->amount <= 0) {
                throw new LogicException('Сумма счёта должна быть больше нуля');
            }

            $invoice->status = FinInvoiceStatus::Issued;
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Отмена счёта: только без платежей.
     */
    public function cancel(FinInvoice $invoice): FinInvoice
    {
        return DB::transaction(function () use ($invoice): FinInvoice {
            $invoice = FinInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status->isClosed()) {
                throw new LogicException('Счёт уже закрыт: оплачен или отменён');
            }

            if ($invoice->payments()->exists()) {
                throw new LogicException('По счёту есть платежи — отмена невозможна');
            }

            $invoice->status = FinInvoiceStatus::Cancelled;
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Оплата счёта: платёж + приход в журнале; полная оплата закрывает счёт.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException если счёт не выставлен, сумма некорректна или превышает остаток
     */
    public function registerPayment(FinInvoice $invoice, array $data, ?int $actorUserId = null): FinPayment
    {
        return DB::transaction(function () use ($invoice, $data, $actorUserId): FinPayment {
            $invoice = FinInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== FinInvoiceStatus::Issued) {
                throw new LogicException('Оплатить можно только выставленный счёт');
            }

            $amount = round((float) $data['amount'], 2);

            if (! is_finite($amount) || $amount <= 0) {
                throw new LogicException('Сумма платежа должна быть больше нуля');
            }

            $remaining = $invoice->remainingAmount();

            if ($amount > $remaining + 0.001) {
                throw new LogicException(sprintf('Сумма превышает остаток по счёту (%s)', number_format($remaining, 2, ',', ' ')));
            }

            $account = FinAccount::query()->where('company_id', $invoice->company_id)->whereKey($data['account_id'])->active()->first();
            if ($account === null) {
                throw new LogicException('Активный счёт не найден в компании');
            }
            if (! empty($data['category_id']) && ! FinCategory::query()->where('company_id', $invoice->company_id)
                ->where('direction', 'income')->active()->whereKey($data['category_id'])->exists()) {
                throw new LogicException('Активная статья дохода не найдена в компании');
            }
            $transaction = FinTransaction::query()->create([
                'company_id' => $invoice->company_id,
                'account_id' => (int) $data['account_id'],
                'category_id' => $data['category_id'] ?? null,
                'direction' => FinDirection::Income,
                'amount' => $amount,
                'occurred_at' => $data['paid_at'] ?? now()->toDateString(),
                'description' => 'Оплата по счёту '.$invoice->number,
                'source' => FinTransactionSource::InvoicePayment,
                'created_by' => $actorUserId,
            ]);

            $payment = FinPayment::query()->create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'account_id' => (int) $data['account_id'],
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorUserId,
            ]);

            if ($invoice->remainingAmount() <= 0.001) {
                $invoice->status = FinInvoiceStatus::Paid;
                $invoice->save();
            }

            return $payment;
        });
    }

    /**
     * Бюджеты месяца с фактическим исполнением.
     *
     * @return Collection<int, FinBudget>
     */
    public function budgetsWithActual(int $companyId, string $month): Collection
    {
        [$start, $end] = $this->periodBounds($month);

        $budgets = FinBudget::query()
            ->where('company_id', $companyId)
            ->whereBetween('period', [$start, $end])
            ->with('category')
            ->orderBy('direction')
            ->orderBy('id')
            ->get();

        $mixed = FinAccount::query()->where('company_id', $companyId)
            ->distinct()->count(DB::raw('UPPER(currency)')) > 1;

        foreach ($budgets as $budget) {
            $query = FinTransaction::query()
                ->where('fin_transactions.company_id', $companyId)
                ->where('fin_transactions.direction', $budget->direction->value)
                ->whereBetween('fin_transactions.occurred_at', [$start, $end]);

            if ($budget->category_id !== null) {
                $query->where('fin_transactions.category_id', $budget->category_id);
            }

            $actuals = $query->join('fin_accounts as account', 'account.id', '=', 'fin_transactions.account_id')
                ->where('account.company_id', $companyId)
                ->selectRaw('UPPER(account.currency) as currency_code, SUM(fin_transactions.amount) as total')
                ->groupByRaw('UPPER(account.currency)')->pluck('total', 'currency_code')
                ->map(fn ($value): float => (float) $value)->all();
            $actual = $mixed ? null : (float) array_sum($actuals);
            $planned = (float) $budget->planned_amount;

            $budget->setAttribute('actual', $actual);
            $budget->setAttribute('actual_by_currency', $actuals);
            $budget->setAttribute(
                'usage_percent',
                ! $mixed && $planned > 0 ? round($actual / $planned * 100, 1) : null,
            );
        }

        return $budgets;
    }

    /**
     * @return array{0:string, 1:string} начало и конец месяца (Y-m-d)
     */
    private function periodBounds(?string $month): array
    {
        $start = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')
            : CarbonImmutable::now()->startOfMonth();

        return [$start->startOfMonth()->toDateString(), $start->endOfMonth()->toDateString()];
    }

    /**
     * Изменение баланса по счетам: приход положительный, расход отрицательный.
     *
     * @return Collection<int|string, float>
     */
    private function deltasByAccount(int $companyId): Collection
    {
        return FinTransaction::query()
            ->where('company_id', $companyId)
            ->selectRaw("account_id, SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END) as delta")
            ->groupBy('account_id')
            ->pluck('delta', 'account_id')
            ->map(static fn (mixed $delta): float => (float) $delta);
    }
}
