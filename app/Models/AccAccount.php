<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\AccAccountKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Счёт плана счетов компании (актив/пассив/капитал/доход/расход).
 */
class AccAccount extends Model
{
    protected $table = 'acc_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => AccAccountKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<AccEntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AccEntryLine::class, 'account_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
