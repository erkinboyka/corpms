<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** BL-07/DB-07/DB-08: счётчик номеров, инкрементируемый под row-level блокировкой. */
class NumberSequence extends Model
{
    protected $table = 'number_sequences';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_value' => 'integer',
        ];
    }
}
