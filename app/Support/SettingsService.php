<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * Настройки системы (п.70 ТЗ): устойчивые значения с fallback на config('corpms.*').
 *
 * Приоритет: БД (settings, group 'corpms') → config → default.
 * Значения кэшируются на время запроса.
 */
class SettingsService
{
    /** Группа в таблице settings. */
    public const GROUP = 'corpms';

    /** @var array<string, string|null> */
    protected array $resolved = [];

    /**
     * Редактируемые настройки панели (ключ = corpms.<dot-key>).
     *
     * @return array<string, array{name: string, type: string, options?: list<string>, group: string}>
     */
    public function definitions(): array
    {
        return [
            'corpms.tasks.max_subtask_depth' => [
                'name' => 'Предел вложенности подзадач',
                'type' => 'int',
                'group' => 'Задачи',
            ],
            'corpms.documents.max_file_size_mb' => [
                'name' => 'Макс. размер файла документа (МБ)',
                'type' => 'int',
                'group' => 'Документы',
            ],
            'corpms.signature.otp_ttl' => [
                'name' => 'Время жизни OTP-кода (мин)',
                'type' => 'int',
                'group' => 'Подписи',
            ],
            'corpms.signature.qr_token_ttl_hours' => [
                'name' => 'Время жизни QR-токена (ч)',
                'type' => 'int',
                'group' => 'Подписи',
            ],
            'corpms.biometric.require_liveness' => [
                'name' => 'Обязательная liveness-проверка',
                'type' => 'bool',
                'group' => 'Посещаемость',
            ],
            'corpms.biometric.offline_after_minutes' => [
                'name' => 'Устройство offline после (мин)',
                'type' => 'int',
                'group' => 'Посещаемость',
            ],
            'corpms.notifications.daily_summary_hour' => [
                'name' => 'Час утренней сводки',
                'type' => 'int',
                'group' => 'Уведомления',
            ],
            'corpms.ai.base_url' => [
                'name' => 'Адрес провайдера',
                'type' => 'string',
                'group' => 'AI-ассистент',
            ],
            'corpms.ai.api_key' => [
                'name' => 'API-ключ',
                'type' => 'secret',
                'group' => 'AI-ассистент',
            ],
            'corpms.ai.model' => [
                'name' => 'Модель',
                'type' => 'string',
                'group' => 'AI-ассистент',
            ],
            'corpms.ai.timeout' => [
                'name' => 'Ожидание ответа, сек.',
                'type' => 'int',
                'group' => 'AI-ассистент',
            ],
        ];
    }

    /** Значение настройки: БД → config → default. */
    public function get(string $fullKey, mixed $default = null): mixed
    {
        if (\array_key_exists($fullKey, $this->resolved)) {
            return $this->resolved[$fullKey];
        }

        $dotKey = $this->dotKey($fullKey);

        // 1) БД
        $row = Setting::query()
            ->where('group', self::GROUP)
            ->where('key', $dotKey)
            ->first();

        if ($row !== null && $row->value !== null) {
            $value = $this->castOut($dotKey, $row->value);
            $this->resolved[$fullKey] = $value;

            return $value;
        }

        // 2) config('corpms.<dot-key>')
        if ($dotKey !== null) {
            $configValue = config('corpms.'.$dotKey);
            if ($configValue !== null) {
                $this->resolved[$fullKey] = $configValue;

                return $configValue;
            }
        }

        $this->resolved[$fullKey] = $default;

        return $default;
    }

    /** Сохранение (или сброс на config, если null). */
    public function set(string $fullKey, mixed $value): void
    {
        $dotKey = $this->dotKey($fullKey);
        $this->resolved[$fullKey] = $value;

        if ($value === null) {
            if ($dotKey !== null) {
                Setting::query()->where('group', self::GROUP)->where('key', $dotKey)->delete();
            }

            return;
        }

        Setting::updateOrCreate([
            'group' => self::GROUP,
            'key' => $dotKey,
        ], ['value' => $this->castIn($dotKey, $value)]);
    }

    /** Текущие значения для панели. */
    public function currentValues(): array
    {
        $result = [];
        foreach ($this->definitions() as $key => $meta) {
            $value = $this->get($key);
            $result[$key] = [
                'value' => $meta['type'] === 'secret' ? '' : $value,
                'configured' => $meta['type'] === 'secret' && $value !== '',
                'in_db' => Setting::query()->where('group', self::GROUP)->where('key', $this->dotKey($key))->exists(),
            ] + $meta;
        }

        return $result;
    }

    protected function dotKey(string $fullKey): ?string
    {
        if (str_starts_with($fullKey, 'corpms.')) {
            return substr($fullKey, strlen('corpms.'));
        }

        return $fullKey;
    }

    protected function castOut(?string $dotKey, string $raw): mixed
    {
        $definition = $dotKey === null ? null : $this->definitions()['corpms.'.$dotKey] ?? null;

        $type = $definition['type'] ?? null;
        if ($type === 'int') {
            return (int) $raw;
        }
        if ($type === 'bool') {
            return in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true);
        }

        return $raw;
    }

    protected function castIn(?string $dotKey, mixed $value): string
    {
        $definition = $dotKey === null ? null : $this->definitions()['corpms.'.$dotKey] ?? null;

        if (($definition['type'] ?? null) === 'bool') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
