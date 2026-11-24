<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\PurchaseObligationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PROC-01: финансовое обязательство перед поставщиком по размещённому заказу.
 */
class PurchaseObligation extends Model
{
    protected $table = 'purchase_obligations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PurchaseObligationStatus::class,
            'amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseObligationPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseObligationPayment::class, 'obligation_id');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function remainingAmount(): float
    {
        return max(0.0, round((float) $this->amount - $this->paidAmount(), 2));
    }
}
