<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\FinInvoiceKind;
use App\Shared\Enums\FinInvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Счёт-фактура или акт: выставленный документ на оплату клиентом.
 */
class FinInvoice extends Model
{
    protected $table = 'fin_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => FinInvoiceKind::class,
            'status' => FinInvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CrmClient::class, 'client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<FinPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(FinPayment::class, 'invoice_id');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function remainingAmount(): float
    {
        return max(0.0, (float) $this->amount - $this->paidAmount());
    }

    /** Выставлен, не оплачен и срок прошёл. */
    public function isOverdue(): bool
    {
        return $this->status === FinInvoiceStatus::Issued
            && $this->due_date !== null
            && $this->due_date->isPast()
            && $this->remainingAmount() > 0;
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', FinInvoiceStatus::Issued->value);
    }
}
