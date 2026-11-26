<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BumiTutorMessage extends Model
{
    protected $table = 'bumi_tutor_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
