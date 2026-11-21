<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\LmsElementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsWhiteboardElement extends Model
{
    protected $table = 'lms_whiteboard_elements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LmsElementType::class,
            'payload' => 'array',
            'is_erased' => 'bool',
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
