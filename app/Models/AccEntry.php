<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\AccEntrySource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Проводка: документ журнала хозяйственных операций (сбалансированный).
 */
class AccEntry extends Model
{
    protected $table = 'acc_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => AccEntrySource::class,
            'entry_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<AccEntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AccEntryLine::class, 'entry_id');
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinTransaction::class, 'finance_transaction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function debitTotal(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    public function creditTotal(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    public function amount(): float
    {
        return $this->debitTotal();
    }
}
