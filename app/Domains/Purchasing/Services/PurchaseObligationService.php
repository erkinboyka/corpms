<?php

declare(strict_types=1);

namespace App\Domains\Purchasing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Models\FinTransaction;
use App\Models\PurchaseObligation;
use App\Models\PurchaseObligationPayment;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Shared\Enums\FinTransactionSource;
use App\Shared\Enums\PurchaseObligationStatus;
use App\Shared\Enums\PurchaseOrderStatus;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * PROC-01: связка «заказ → обязательство → оплата».
 *
 * Все операции идут в транзакции и с блокировкой обязательства: сумма оплаты
 * проверяется по актуальной задолженности, а уникальный reference не даёт
 * провести одну платёжку дважды. Приём на склад остаётся отдельным шагом.
 */
class PurchaseObligationService
{
    protected AuditService $audit;

    protected NumberGenerator $numbers;

    public function __construct(AuditService $audit, NumberGenerator $numbers)
    {
        $this->audit = $audit;
        $this->numbers = $numbers;
    }

    /** Создать обязательство ровно один раз при размещении заказа. */
    public function ensureForOrder(PurchaseOrder $order, ?User $user): PurchaseObligation
    {
        return DB::transaction(function () use ($order, $user): PurchaseObligation {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $existing = PurchaseObligation::query()
                ->where('purchase_order_id', $locked->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if (! in_array($locked->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)) {
                throw new InvalidArgumentException('Обязательство можно создать только по активному заказу');
            }

            $obligation = PurchaseObligation::query()->create([
                'company_id' => $locked->company_id,
                'purchase_order_id' => $locked->id,
                'supplier_id' => $locked->supplier_id,
                'number' => $this->nextNumber($locked->company_id),
                'amount' => $locked->total,
                'currency' => 'TJS',
                'status' => PurchaseObligationStatus::Open,
                'created_by' => $user?->id,
            ]);

            $this->audit->log($user, 'purchase.obligation_created', 'purchase_obligation', $obligation->id, null, [
                'number' => $obligation->number,
                'purchase_order_id' => $locked->id,
                'amount' => (float) $obligation->amount,
            ]);

            return $obligation;
        });
    }

    /**
     * Провести оплату обязательства.
     *
     * @param  array<string,mixed>  $data
     */
    public function pay(PurchaseObligation $obligation, array $data, ?User $user): PurchaseObligationPayment
    {
        return DB::transaction(function () use ($obligation, $data, $user): PurchaseObligationPayment {
            $locked = PurchaseObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPayable()) {
                throw new InvalidArgumentException('Обязательство закрыто или отменено');
            }

            $reference = trim((string) $data['reference']);

            $duplicate = PurchaseObligationPayment::query()
                ->where('obligation_id', $locked->id)
                ->where('reference', $reference)
                ->first();

            if ($duplicate !== null) {
                return $duplicate;
            }

            $amount = round((float) $data['amount'], 2);
            $remaining = $locked->remainingAmount();

            if ($amount <= 0 || $amount > $remaining) {
                throw new InvalidArgumentException('Сумма оплаты превышает задолженность или неположительна');
            }

            $transaction = FinTransaction::query()->create([
                'company_id' => $locked->company_id,
                'account_id' => $data['account_id'],
                'category_id' => $data['category_id'] ?? null,
                'project_id' => null,
                'direction' => 'expense',
                'amount' => $amount,
                'occurred_at' => $data['paid_at'],
                'source' => FinTransactionSource::PurchaseObligationPayment,
                'description' => 'Оплата обязательства '.$locked->number.' (заказ '.$locked->order->number.')',
                'created_by' => $user?->id,
            ]);

            $payment = PurchaseObligationPayment::query()->create([
                'company_id' => $locked->company_id,
                'obligation_id' => $locked->id,
                'account_id' => $data['account_id'],
                'transaction_id' => $transaction->id,
                'category_id' => $data['category_id'] ?? null,
                'amount' => $amount,
                'paid_at' => $data['paid_at'],
                'method' => $data['method'],
                'reference' => $reference,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $newStatus = $locked->remainingAmount() <= 0.005
                ? PurchaseObligationStatus::Paid
                : PurchaseObligationStatus::PartiallyPaid;

            $locked->status = $newStatus;
            $locked->save();

            $this->audit->log($user, 'purchase.obligation_paid', 'purchase_obligation', $locked->id, null, [
                'number' => $locked->number,
                'reference' => $reference,
                'amount' => $amount,
            ]);

            return $payment;
        });
    }

    /** Отменить обязательство можно только если по нему не было оплат. */
    public function cancel(PurchaseObligation $obligation, ?User $user): void
    {
        DB::transaction(function () use ($obligation, $user): void {
            $locked = PurchaseObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if ($locked->payments()->exists()) {
                throw new RuntimeException('Нельзя отменить обязательство с оплатами');
            }

            $locked->status = PurchaseObligationStatus::Cancelled;
            $locked->save();

            $this->audit->log($user, 'purchase.obligation_cancelled', 'purchase_obligation', $locked->id, null, [
                'number' => $locked->number,
            ]);
        });
    }

    /** Номер обязательства: своя последовательность на компанию и год. */
    private function nextNumber(int $companyId): string
    {
        $year = (int) now()->format('Y');
        $prefix = 'OBL-'.$year.'-';
        $used = PurchaseObligation::query()
            ->where('company_id', $companyId)
            ->where('number', 'like', $prefix.'%')
            ->pluck('number');

        $next = $this->numbers->next(
            'purchase_obligation:'.$companyId,
            $year,
            fn (): int => $this->numbers->maxSuffix($prefix, $used),
        );

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
