<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\BumiChannelType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Канал, откуда клиенты пишут Bumi (сайт, Telegram, WhatsApp, Instagram).
 * Учётные данные и webhook-секреты хранятся зашифрованно.
 */
class BumiChannel extends Model
{
    protected $table = 'bumi_channels';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'type' => BumiChannelType::class,
            'enabled' => 'bool',
            'auto_reply' => 'bool',
        ];
    }

    /** @return Attribute<array<string, mixed>, array<string, mixed>> */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }
                try {
                    $plain = str_starts_with($value, 'enc:v1:')
                        ? Crypt::decryptString(substr($value, 7))
                        : $value;
                    $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);

                    return is_array($decoded) ? $decoded : [];
                } catch (JsonException | \Throwable) {
                    return [];
                }
            },
            set: function (array|string|null $value): string {
                if (! is_array($value)) {
                    return '';
                }
                try {
                    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } catch (JsonException) {
                    return '';
                }

                return 'enc:v1:'.Crypt::encryptString($json);
            },
        );
    }

    /** Безопасное чтение одного поля учётных данных. */
    public function credential(string $key): string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** Канал готов к приёму сообщений: сайт — всегда, остальные — после ключей. */
    public function isConfigured(): bool
    {
        if ($this->type === BumiChannelType::Website) {
            return true;
        }

        foreach ($this->type->secretFields() as $field) {
            if ($this->credential($field) === '' && ! in_array($field, ['mode'], true)) {
                return false;
            }
        }

        return true;
    }

    public function statusLabel(): string
    {
        if (! $this->enabled) {
            return __('Отключён');
        }

        return $this->isConfigured() ? __('Подключён') : __('Требует настройки');
    }

    public function statusClass(): string
    {
        if (! $this->enabled) {
            return 'crm-chip--grey';
        }

        return $this->isConfigured() ? 'crm-chip--green' : 'crm-chip--orange';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BumiAgent::class, 'agent_id');
    }

    /** @return HasMany<BumiChannelContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(BumiChannelContact::class, 'channel_id');
    }

    /** @return HasMany<BumiChannelMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(BumiChannelMessage::class, 'channel_id');
    }

    /** URL, который нужно указать на стороне провайдера. */
    public function webhookUrl(): string
    {
        $base = '/api/bumi/channels/'.$this->public_token;

        return match ($this->type) {
            BumiChannelType::Website => '',
            BumiChannelType::Telegram => url($base.'/webhook'),
            BumiChannelType::WhatsApp => url($base.'/meta/verify'),
            BumiChannelType::Instagram => url($base.'/instagram/verify'),
            BumiChannelType::GreenApi => url($base.'/greenapi/verify'),
        };
    }

    /** GET-проверка подписки Meta/Instagram/Green API. */
    public function verifyUrl(): string
    {
        $url = $this->webhookUrl();

        return $this->type === BumiChannelType::Website || $this->type === BumiChannelType::Telegram ? '' : $url;
    }
}
