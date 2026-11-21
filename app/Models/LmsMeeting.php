<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\LmsMeetingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsMeeting extends Model
{
    protected $table = 'lms_meetings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LmsMeetingStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class, 'lesson_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'host_employee_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(LmsMeetingParticipant::class, 'meeting_id')->orderBy('id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(LmsSignal::class, 'meeting_id')->orderBy('id');
    }
}
