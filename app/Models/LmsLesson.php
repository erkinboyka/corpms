<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsLesson extends Model
{
    protected $table = 'lms_lessons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_required' => 'bool',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LmsLessonProgress::class, 'lesson_id');
    }

    public function whiteboards(): HasMany
    {
        return $this->hasMany(LmsWhiteboard::class, 'lesson_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(LmsMeeting::class, 'lesson_id')->latest();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LmsAssignment::class, 'lesson_id')->latest();
    }
}
