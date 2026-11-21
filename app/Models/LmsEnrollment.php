<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\LmsEnrollmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsEnrollment extends Model
{
    protected $table = 'lms_enrollments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LmsEnrollmentStatus::class,
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LmsLessonProgress::class, 'enrollment_id');
    }
}
