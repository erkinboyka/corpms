<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\LmsProgressStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsLessonProgress extends Model
{
    protected $table = 'lms_lesson_progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LmsProgressStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class, 'lesson_id');
    }
}
