<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\OffsiteRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceOffsiteRequest extends Model
{
    protected $table = 'attendance_offsite_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OffsiteRequestStatus::class,
            'date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'decided_by');
    }
}
