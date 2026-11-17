<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\AnomalyStatus;
use App\Shared\Enums\AnomalyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAnomaly extends Model
{
    protected $table = 'attendance_anomalies';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'type' => AnomalyType::class,
            'status' => AnomalyStatus::class,
            'date' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }
}
