<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Кастомный дашборд BI: набор виджетов по метрикам витрины.
 *
 * Поля генерации (AI): status = draft|generating|ready|error, origin =
 * manual|ai, instruction — запрос пользователя, по которому собран дашборд.
 */
class BiDashboard extends Model
{
    protected $table = 'bi_dashboards';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BiWidget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(BiWidget::class, 'dashboard_id');
    }
}
