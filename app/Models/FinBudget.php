<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\FinDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Месячный бюджет по статье (или общий по направлению).
 */
class FinBudget extends Model
{
    protected $table = 'fin_budgets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'direction' => FinDirection::class,
            'planned_amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinCategory::class, 'category_id');
    }
}
