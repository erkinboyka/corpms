<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\LmsAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsAssignment extends Model
{
    protected $table = 'lms_assignments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LmsAssignmentStatus::class,
            'due_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(LmsSubmission::class, 'assignment_id')->latest();
    }
}
