<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\FinDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Статья доходов/расходов — аналитика операций и бюджетов.
 */
class FinCategory extends Model
{
    protected $table = 'fin_categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direction' => FinDirection::class,
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
        return $this->hasMany(FinTransaction::class, 'category_id');
    }

    /** @return HasMany<FinBudget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(FinBudget::class, 'category_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
