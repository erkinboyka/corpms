<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\AccDocumentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Первичный документ бухгалтерии: счёт, акт, платёжное поручение и т.п.
 */
class AccDocument extends Model
{
    protected $table = 'acc_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => AccDocumentKind::class,
            'document_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(AccEntry::class, 'entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
