<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\LmsCourseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsCourse extends Model
{
    use SoftDeletes;

    protected $table = 'lms_courses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => LmsCourseStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(LmsLesson::class, 'course_id')->orderBy('sort_order');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(LmsEnrollment::class, 'course_id');
    }

    public function whiteboards(): HasMany
    {
        return $this->hasMany(LmsWhiteboard::class, 'course_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(LmsMeeting::class, 'course_id')->latest();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LmsAssignment::class, 'course_id')->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LmsActivity::class, 'course_id')->latest();
    }
}
