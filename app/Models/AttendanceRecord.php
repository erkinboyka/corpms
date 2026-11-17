<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\AttendanceSource;
use App\Shared\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    protected $table = 'attendance_records';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
            'date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'schedule_snapshot' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class, 'attendance_record_id');
    }
}
