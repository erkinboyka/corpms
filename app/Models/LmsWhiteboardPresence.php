<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsWhiteboardPresence extends Model
{
    protected $table = 'lms_whiteboard_presence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'seen_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function whiteboard(): BelongsTo
    {
        return $this->belongsTo(LmsWhiteboard::class, 'whiteboard_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
