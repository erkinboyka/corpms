<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsWhiteboard extends Model
{
    protected $table = 'lms_whiteboards';

    protected $guarded = [];

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

    public function elements(): HasMany
    {
        return $this->hasMany(LmsWhiteboardElement::class, 'whiteboard_id')->orderBy('id');
    }

    public function presence(): HasMany
    {
        return $this->hasMany(LmsWhiteboardPresence::class, 'whiteboard_id');
    }
}
