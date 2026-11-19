<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $table = 'settings';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value !== null && str_starts_with($value, 'enc:v1:') && $this->isSecret()
                ? Crypt::decryptString(substr($value, 7)) : $value,
            set: fn (?string $value): ?string => $value !== null && $value !== '' && $this->isSecret()
                ? 'enc:v1:'.Crypt::encryptString($value) : $value,
        );
    }

    private function isSecret(): bool
    {
        return ($this->attributes['group'] ?? null) === 'corpms' && ($this->attributes['key'] ?? null) === 'ai.api_key';
    }
}
