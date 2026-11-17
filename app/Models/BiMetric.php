<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка витрины BI: значение метрики за день.
 */
class BiMetric extends Model
{
    protected $table = 'bi_metrics';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'value' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
