<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Services;

use App\Models\AccAccount;
use App\Models\AccEntry;
use App\Models\AccEntryLine;
use App\Models\FinAccount;
use App\Models\FinTransaction;
use App\Shared\Enums\AccAccountKind;
use App\Shared\Enums\AccEntrySource;
use App\Shared\Enums\FinAccountKind;
use App\Shared\Enums\FinDirection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Бухгалтерский движок (Этап E4): план счетов, сбалансированные проводки,
 * авто-проводки из финансов, оборотка, ОПиУ и баланс.
 *
 * Правила:
 *   - проводка сбалансирована: сумма дебета = сумма кредита (допуск 0,005);
 *   - в строке ровно одна сторона: либо дебет, либо кредит;
 *   - одна операция финансов даёт ровно одну проводку (идемпотентность синка);
 *   - счета проводки обязаны принадлежать компании.
 */
class AccountingService
{
    /**
     * Типовой упрощённый план счетов (РФ): активы, пассивы, капитал, доходы, расходы.
     *
     * @var array<int, array{code:string, name:string, kind:AccAccountKind}>
     */
    public const DEFAULT_CHART = [
        ['code' => '50', 'name' => 'Касса', 'kind' => AccAccountKind::Asset],
        ['code' => '51', 'name' => 'Расчётные счета', 'kind' => AccAccountKind::Asset],
        ['code' => '60', 'name' => 'Расчёты с поставщиками и подрядчиками', 'kind' => AccAccountKind::Liability],
        ['code' => '62', 'name' => 'Расчёты с покупателями и заказчиками', 'kind' => AccAccountKind::Asset],
        ['code' => '68', 'name' => 'Расчёты по налогам и сборам', 'kind' => AccAccountKind::Liability],
        ['code' => '80', 'name' => 'Уставный капитал', 'kind' => AccAccountKind::Equity],
        ['code' => '84', 'name' => 'Нераспределённая прибыль', 'kind' => AccAccountKind::Equity],
        ['code' => '90.1', 'name' => 'Выручка', 'kind' => AccAccountKind::Income],
        ['code' => '90.2', 'name' => 'Себестоимость продаж', 'kind' => AccAccountKind::Expense],
        ['code' => '91.1', 'name' => 'Прочие доходы', 'kind' => AccAccountKind::Income],
        ['code' => '91.2', 'name' => 'Прочие расходы', 'kind' => AccAccountKind::Expense],
        ['code' => '99', 'name' => 'Прибыли и убытки', 'kind' => AccAccountKind::Equity],
    ];

    /** Коды счетов для авто-проводок из финансов. */
    private const CASH_CODE = ['cash' => '50', 'bank' => '51'];

    private const INCOME_CODE = '90.1';

    private const EXPENSE_CODE = '91.2';

    /**
     * Заполнение типового плана счетов: создаёт недостающие коды.
     *
     * @return int сколько счетов создано
     */
    public function ensureDefaultChart(int $companyId): int
    {
        $existing = AccAccount::query()
            ->where('company_id', $companyId)
            ->pluck('code')
            ->all();

        $created = 0;

        foreach (self::DEFAULT_CHART as $row) {
            if (in_array($row['code'], $existing, true)) {
                continue;
            }

            AccAccount::query()->create([
                'company_id' => $companyId,
                'code' => $row['code'],
                'name' => $row['name'],
                'kind' => $row['kind'],
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }

    /** @return Collection<int, AccAccount> */
    public function chart(int $companyId): Collection
    {
        return AccAccount::query()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get();
    }

    /**
     * Ручная проводка: проверка строк и баланса, запись в транзакции.
     *
     * @param  array<int, array{account_id:int|string, debit:mixed, credit:mixed, description?:?string}>  $lines
     * @param  array{entry_date:string, description?:?string}  $data
     *
     * @throws LogicException если строки некорректны или проводка не сбалансирована
     */
    public function createEntry(int $companyId, array $data, array $lines, ?int $userId = null): AccEntry
    {
        $prepared = [];
        $debitTotal = 0.0;
        $creditTotal = 0.0;

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            if ($debit > 0 && $credit > 0) {
                throw new LogicException('В строке проводки указывается либо дебет, либо кредит');
            }

            $accountId = (int) ($line['account_id'] ?? 0);

            $account = AccAccount::query()
                ->where('company_id', $companyId)
                ->find($accountId);

            if ($account === null) {
                throw new LogicException('Счёт проводки не найден в компании');
            }

            $debitTotal += $debit;
            $creditTotal += $credit;

            $prepared[] = [
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if (count($prepared) < 2) {
            throw new LogicException('Проводка должна содержать минимум две строки');
        }

        if (abs($debitTotal - $creditTotal) > 0.005) {
            throw new LogicException(sprintf(
                'Проводка не сбалансирована: дебет %s, кредит %s',
                number_format($debitTotal, 2, ',', ' '),
                number_format($creditTotal, 2, ',', ' '),
            ));
        }

        return DB::transaction(function () use ($companyId, $data, $prepared, $userId): AccEntry {
            $entry = AccEntry::query()->create([
                'company_id' => $companyId,
                'entry_date' => $data['entry_date'],
                'description' => $data['description'] ?? null,
                'source' => AccEntrySource::Manual,
                'created_by' => $userId,
            ]);

            foreach ($prepared as $line) {
                $entry->lines()->create($line);
            }

            return $entry;
        });
    }

    /**
     * Проводки из финансов: каждая операция без проводки получает сбалансированную.
     *
     * @return int сколько проводок создано
     */
    public function syncFinance(int $companyId, ?string $from = null, ?string $to = null): int
    {
        if (AccAccount::query()->where('company_id', $companyId)->exists() === false) {
            $this->ensureDefaultChart($companyId);
        }

        $existing = AccEntry::query()
            ->where('company_id', $companyId)
            ->whereNotNull('finance_transaction_id')
            ->pluck('finance_transaction_id')
            ->all();

        $transactions = FinTransaction::query()
            ->where('company_id', $companyId)
            ->when($from !== null, static fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->where('occurred_at', '<=', $to))
            ->whereNotIn('id', $existing)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $created = 0;

        foreach ($transactions as $transaction) {
            if ($this->postFinanceTransaction($transaction) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Авто-проводка одной операции финансов (идемпотентно).
     *
     * Доход: дебет касса/банк — кредит 90.1.
     * Расход: дебет 91.2 — кредит касса/банк.
     *
     * @return AccEntry|null null, если проводка уже есть или не хватает счетов
     */
    public function postFinanceTransaction(FinTransaction $transaction): ?AccEntry
    {
        $exists = AccEntry::query()
            ->where('company_id', $transaction->company_id)
            ->where('finance_transaction_id', $transaction->id)
            ->exists();

        if ($exists) {
            return null;
        }

        $financeAccount = FinAccount::query()
            ->where('company_id', $transaction->company_id)
            ->find($transaction->account_id);

        $cashCode = self::CASH_CODE[$financeAccount?->kind === FinAccountKind::Cash ? 'cash' : 'bank'];

        $debitCode = $transaction->direction === FinDirection::Income ? $cashCode : self::EXPENSE_CODE;
        $creditCode = $transaction->direction === FinDirection::Income ? self::INCOME_CODE : $cashCode;

        $debit = $this->accountByCode((int) $transaction->company_id, $debitCode);
        $credit = $this->accountByCode((int) $transaction->company_id, $creditCode);

        if ($debit === null || $credit === null) {
            return null;
        }

        return DB::transaction(function () use ($transaction, $debit, $credit): AccEntry {
            $entry = AccEntry::query()->create([
                'company_id' => $transaction->company_id,
                'entry_date' => $transaction->occurred_at?->toDateString() ?? now()->toDateString(),
                'description' => $transaction->description ?: ($transaction->direction === FinDirection::Income ? 'Доход' : 'Расход'),
                'source' => AccEntrySource::Finance,
                'finance_transaction_id' => $transaction->id,
                'created_by' => $transaction->created_by,
            ]);

            $entry->lines()->create([
                'account_id' => $debit->id,
                'debit' => $transaction->amount,
                'credit' => 0,
                'description' => $entry->description,
            ]);

            $entry->lines()->create([
                'account_id' => $credit->id,
                'debit' => 0,
                'credit' => $transaction->amount,
                'description' => $entry->description,
            ]);

            return $entry;
        });
    }

    /**
     * Оборотно-сальдовая ведомость за период: обороты и сальдо по каждому счёту.
     *
     * @return Collection<int, AccAccount>
     */
    public function trialBalance(int $companyId, string $from, string $to): Collection
    {
        $turnovers = AccEntryLine::query()
            ->join('acc_entries', 'acc_entries.id', '=', 'acc_entry_lines.entry_id')
            ->where('acc_entries.company_id', $companyId)
            ->whereBetween('acc_entries.entry_date', [$from, $to])
            ->groupBy('acc_entry_lines.account_id')
            ->selectRaw('acc_entry_lines.account_id as account_id, SUM(acc_entry_lines.debit) as debit_sum, SUM(acc_entry_lines.credit) as credit_sum')
            ->get()
            ->keyBy('account_id');

        return AccAccount::query()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get()
            ->each(function (AccAccount $account) use ($turnovers): void {
                $row = $turnovers->get($account->id);
                $debit = (float) ($row->debit_sum ?? 0);
                $credit = (float) ($row->credit_sum ?? 0);

                $account->setAttribute('debit_turnover', $debit);
                $account->setAttribute('credit_turnover', $credit);
                $account->setAttribute('balance', round($debit - $credit, 2));
            });
    }

    /**
     * Отчёт о прибылях и убытках: доходы минус расходы за период.
     *
     * @return array{income:float, expense:float, profit:float, rows:Collection<int, AccAccount>}
     */
    public function profitAndLoss(int $companyId, string $from, string $to): array
    {
        $rows = $this->trialBalance($companyId, $from, $to)
            ->filter(static fn (AccAccount $account): bool => in_array($account->kind, [AccAccountKind::Income, AccAccountKind::Expense], true))
            ->values();

        $income = 0.0;
        $expense = 0.0;

        foreach ($rows as $row) {
            if ($row->kind === AccAccountKind::Income) {
                $income += (float) $row->getAttribute('credit_turnover') - (float) $row->getAttribute('debit_turnover');
            } else {
                $expense += (float) $row->getAttribute('debit_turnover') - (float) $row->getAttribute('credit_turnover');
            }
        }

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'profit' => round($income - $expense, 2),
            'rows' => $rows,
        ];
    }

    /**
     * Упрощённый баланс на дату: активы = пассивы + капитал + финансовый результат.
     *
     * @return array{assets:float, liabilities:float, equity:float, profit:float, balanced:bool, rows:Collection<int, AccAccount>}
     */
    public function balanceSheet(int $companyId, string $to): array
    {
        $rows = $this->trialBalance($companyId, '1970-01-01', $to);

        $assets = 0.0;
        $liabilities = 0.0;
        $equity = 0.0;
        $income = 0.0;
        $expense = 0.0;

        foreach ($rows as $row) {
            $balance = (float) $row->getAttribute('balance');

            match ($row->kind) {
                AccAccountKind::Asset => $assets += $balance,
                AccAccountKind::Liability => $liabilities += -$balance,
                AccAccountKind::Equity => $equity += -$balance,
                AccAccountKind::Income => $income += -$balance,
                AccAccountKind::Expense => $expense += $balance,
            };
        }

        $profit = round($income - $expense, 2);
        $assets = round($assets, 2);
        $liabilities = round($liabilities, 2);
        $equity = round($equity, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'profit' => $profit,
            'balanced' => abs($assets - ($liabilities + $equity + $profit)) < 0.01,
            'rows' => $rows,
        ];
    }

    private function accountByCode(int $companyId, string $code): ?AccAccount
    {
        return AccAccount::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();
    }
}
