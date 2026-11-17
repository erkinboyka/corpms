<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\FinAccountKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Касса или расчётный счёт компании. Баланс считается по журналу операций.
 */
class FinAccount extends Model
{
    protected $table = 'fin_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => FinAccountKind::class,
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<FinTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinTransaction::class, 'account_id');
    }

    /** @return HasMany<FinPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(FinPayment::class, 'account_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
