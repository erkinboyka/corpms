<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\BiometricEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricEvent extends Model
{
    protected $table = 'biometric_events';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'processing_status' => BiometricEventStatus::class,
            'liveness_passed' => 'bool',
            'liveness_required' => 'bool',
            'raw_payload' => 'array',
            'confidence' => 'float',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
