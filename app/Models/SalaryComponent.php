<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\PayrollCalculation;
use App\Shared\Enums\PayrollComponentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Компонент начисления/удержания компании.
 * Правило расчёта задаётся конфигом (calculation + rate), не хардкодом.
 */
class SalaryComponent extends Model
{
    protected $table = 'salary_components';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'kind' => PayrollComponentKind::class,
            'calculation' => PayrollCalculation::class,
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
