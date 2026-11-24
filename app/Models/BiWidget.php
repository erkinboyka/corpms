<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\BiWidgetKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Виджет дашборда BI.
 *
 * Два режима: metric (KPI/тренд по метрике витрины, content_mode='metric')
 * и SQL-виджет сгенерированный ИИ (content_mode='sql', widget_family +
 * query_spec в формате Datavue: queries/shape/presentation).
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
            'span' => 'integer',
            'query_spec' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    /** SQL-виджет, сгенерированный ИИ. */
    public function isAiWidget(): bool
    {
        return $this->content_mode === 'sql' && $this->widget_family !== null;
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(BiDashboard::class, 'dashboard_id');
    }
}
