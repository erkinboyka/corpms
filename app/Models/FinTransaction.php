<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\FinDirection;
use App\Shared\Enums\FinTransactionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Операция журнала доходов/расходов. Источник — ручной ввод или оплата счёта.
 */
class FinTransaction extends Model
{
    protected $table = 'fin_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direction' => FinDirection::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'date',
            'source' => FinTransactionSource::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Сумма со знаком: приход положительный, расход отрицательный. */
    public function signedAmount(): float
    {
        return (float) $this->amount * $this->direction->sign();
    }
}
