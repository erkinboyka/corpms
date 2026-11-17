<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\PayrollPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Расчётный период (зарплатная ведомость).
 * Статусная машина: draft → calculated → approved → paid (см. PayrollCalculationService).
 */
class PayrollPeriod extends Model
{
    protected $table = 'payroll_periods';
    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'status' => PayrollPeriodStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'period_id');
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** Подпись периода: «01.10.2026 — 31.10.2026». */
    public function label(): string
    {
        return $this->period_start->format('d.m.Y') . ' — ' . $this->period_end->format('d.m.Y');
    }
}
