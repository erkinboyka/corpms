<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\BiWidgetKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Виджет дашборда BI: KPI-карточка или тренд по метрике витрины.
 */
class BiWidget extends Model
{
    protected $table = 'bi_widgets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => BiWidgetKind::class,
            'position' => 'integer',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(BiDashboard::class, 'dashboard_id');
    }
}
