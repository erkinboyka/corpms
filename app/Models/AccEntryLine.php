<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка проводки: дебет или кредит одного счёта.
 */
class AccEntryLine extends Model
{
    protected $table = 'acc_entry_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(AccEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccAccount::class, 'account_id');
    }

    public function amount(): float
    {
        return max((float) $this->debit, (float) $this->credit);
    }
}
