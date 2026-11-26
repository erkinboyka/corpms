<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AiProvider extends Model
{
    protected $table = 'ai_providers';

    protected $guarded = [];

    protected $casts = [
        'company_id' => 'integer',
        'models' => 'array',
        'timeout' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Ключ в БД хранится зашифрованно, как и corpms.ai.api_key. */
    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value !== null && str_starts_with($value, 'enc:v1:')
                ? Crypt::decryptString(substr($value, 7)) : $value,
            set: fn (?string $value): ?string => $value !== null && $value !== ''
                ? 'enc:v1:'.Crypt::encryptString($value) : $value,
        );
    }

    /** @return list<string> */
    public function modelList(): array
    {
        $models = array_values(array_filter(array_map(
            static fn (mixed $model): string => trim((string) $model),
            is_array($this->models) ? $this->models : [],
        )));

        if ($this->default_model !== null && $this->default_model !== '' && ! in_array($this->default_model, $models, true)) {
            $models[] = $this->default_model;
        }

        return array_values(array_unique($models));
    }

    public function maskedKey(): string
    {
        return $this->api_key ? '••••••' : '';
    }
}
