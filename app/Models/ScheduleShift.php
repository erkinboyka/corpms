<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleShift extends Model
{
    protected $table = 'schedule_shifts';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }
}
