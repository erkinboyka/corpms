<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaceVerificationLog extends Model
{
    protected $table = 'face_verification_logs';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'liveness_passed' => 'bool',
            'created_at' => 'datetime',
        ];
    }
}
