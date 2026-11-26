<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * Настройки системы (п.70 ТЗ): устойчивые значения с fallback на config('corpms.*').
 *
 * Межтенантность: строка settings с company_id — переопределение конкретной
 * компании, строка company_id NULL — общая (установочная) настройка.
 * Приоритет: настройка компании → общая настройка БД → config → default.
 *
 * Контекст компании (useCompany) задаётся явно вне HTTP-контекста:
 * device API (DeviceTokenAuth), AI/очередь (AiAssistantService и др.),
 * консоль. В web-запросе компания берётся из сотрудника auth()->user().
 * Контекст хранится в атрибутах текущего Request и не перетекает
 * между запросами (в том числе в тестах и под Octane).
 *
 * Значения кэшируются на время запроса в рамках ключа «компания|ключ».
 */
class SettingsService
{
    /** Группа в таблице settings. */
    public const GROUP = 'corpms';

    /** Атрибут Request с явным контекстом компании (int|null). */
    protected const CONTEXT_ATTR = 'corpms_settings_company_id';

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
                'name' => __('Предел вложенности подзадач'),
                'type' => 'int',
                'group' => __('Задачи'),
            ],
            'corpms.documents.max_file_size_mb' => [
                'name' => 'Макс. размер файла документа (МБ)',
                'type' => 'int',
                'group' => __('Документы'),
            ],
            'corpms.documents.max_file_count' => [
                'name' => __('Макс. файлов на документ'),
                'type' => 'int',
                'group' => __('Документы'),
            ],
            'corpms.signature.otp_ttl' => [
                'name' => 'Время жизни OTP-кода (мин)',
                'type' => 'int',
                'group' => __('Подписи'),
            ],
            'corpms.signature.qr_token_ttl_hours' => [
                'name' => 'Время жизни QR-токена (ч)',
                'type' => 'int',
                'group' => __('Подписи'),
            ],
            'corpms.biometric.require_liveness' => [
                'name' => __('Обязательная liveness-проверка'),
                'type' => 'bool',
                'group' => __('Посещаемость'),
            ],
            'corpms.biometric.offline_after_minutes' => [
                'name' => 'Устройство offline после (мин)',
                'type' => 'int',
                'group' => __('Посещаемость'),
            ],
            'corpms.notifications.daily_summary_hour' => [
                'name' => __('Час утренней сводки'),
                'type' => 'int',
                'group' => __('Уведомления'),
            ],
            'corpms.ai.base_url' => [
                'name' => __('Адрес провайдера'),
                'type' => 'string',
                'group' => __('AI-ассистент'),
            ],
            'corpms.ai.image_model' => [
                'name' => __('Модель генерации PNG'),
                'type' => 'string',
                'group' => __('AI-ассистент'),
            ],
            'corpms.ai.api_key' => [
                'name' => __('API-ключ'),
                'type' => 'secret',
                'group' => __('AI-ассистент'),
            ],
            'corpms.ai.model' => [
                'name' => __('Модель'),
                'type' => 'string',
                'group' => __('AI-ассистент'),
            ],
            'corpms.ai.embedding_model' => [
                'name' => __('Модель /embeddings'),
                'type' => 'string',
                'group' => __('AI-ассистент'),
            ],
            'corpms.ai.timeout' => [
                'name' => __('Ожидание ответа, сек.'),
                'type' => 'int',
                'group' => __('AI-ассистент'),
            ],
        ];
    }

    /**
     * Задать контекст компании для последующих чтений/записей настроек
     * (device API, AI-сервисы вне web-запроса, очередь, консоль).
     * null — работать с общими настройками (company_id IS NULL).
     */
    public function useCompany(?int $companyId): void
    {
        request()->attributes->set(self::CONTEXT_ATTR, $companyId !== null ? (int) $companyId : null);
        $this->resolved = [];
    }

    /**
     * Текущий контекст компании: явный (useCompany) → сотрудник
     * auth()->user() → null (общие настройки).
     */
    public function companyId(): ?int
    {
        $attributes = request()->attributes;

        if ($attributes->has(self::CONTEXT_ATTR)) {
            $value = $attributes->get(self::CONTEXT_ATTR);

            return $value !== null ? (int) $value : null;
        }

        $employee = auth()->user()?->employee;

        return $employee?->company_id !== null ? (int) $employee->company_id : null;
    }

    /** Значение настройки: компания → общая строка БД → config → default. */
    public function get(string $fullKey, mixed $default = null): mixed
    {
        $companyId = $this->companyId();
        $memoKey = $this->memoKey($fullKey, $companyId);

        if (\array_key_exists($memoKey, $this->resolved)) {
            return $this->resolved[$memoKey];
        }

        $dotKey = $this->dotKey($fullKey);

        // 1) БД: строка компании имеет приоритет над общей (company_id NULL);
        //    в общем контексте читаются только строки company_id IS NULL
        $rows = Setting::query()
            ->where('group', self::GROUP)
            ->where('key', $dotKey)
            ->when(
                $companyId === null,
                fn ($query) => $query->whereNull('company_id'),
                fn ($query) => $query->where(
                    fn ($scope) => $scope->whereNull('company_id')->orWhere('company_id', $companyId),
                ),
            )
            ->get();

        $row = $companyId !== null
            ? $rows->first(fn (Setting $setting): bool => (int) $setting->company_id === $companyId)
                ?? $rows->first(fn (Setting $setting): bool => $setting->company_id === null)
            : $rows->first();

        // A tenant-supplied endpoint must never receive the installation's API key.
        // Inherited credentials are valid only for the inherited provider address.
        if ($dotKey === 'ai.api_key' && $companyId !== null && ($row === null || $row->company_id === null)) {
            $companyUrl = Setting::query()->where('group', self::GROUP)->where('key', 'ai.base_url')->where('company_id', $companyId)->value('value');
            $globalUrl = Setting::query()->where('group', self::GROUP)->where('key', 'ai.base_url')->whereNull('company_id')->value('value')
                ?? config('corpms.ai.base_url', '');
            if ($companyUrl !== null && rtrim((string) $companyUrl, '/') !== rtrim((string) $globalUrl, '/')) {
                return $this->resolved[$memoKey] = '';
            }
        }

        if ($row !== null && $row->value !== null) {
            $value = $this->castOut($dotKey, $row->value);
            $this->resolved[$memoKey] = $value;

            return $value;
        }

        // 2) config('corpms.<dot-key>')
        if ($dotKey !== null) {
            $configValue = config('corpms.'.$dotKey);
            if ($configValue !== null) {
                $this->resolved[$memoKey] = $configValue;

                return $configValue;
            }
        }

        $this->resolved[$memoKey] = $default;

        return $default;
    }

    /**
     * Сохранение в текущий контекст компании (или сброс строки этого
     * контекста на fallback: общая строка → config, если null).
     */
    public function set(string $fullKey, mixed $value): void
    {
        $dotKey = $this->dotKey($fullKey);
        $companyId = $this->companyId();
        $this->resolved = [];

        $scope = [
            'group' => self::GROUP,
            'key' => $dotKey,
            'company_id' => $companyId,
        ];

        if ($value === null) {
            if ($dotKey !== null) {
                Setting::query()->where($scope)->delete();
            }

            return;
        }

        Setting::updateOrCreate($scope, ['value' => $this->castIn($dotKey, $value)]);
    }

    /**
     * Текущие значения для панели.
     *
     * @return array<string, array{value: mixed, configured: bool, in_db: bool, scope: string} & array{name: string, type: string, group: string}>
     */
    public function currentValues(): array
    {
        $companyId = $this->companyId();
        $dotKeys = array_map(
            fn (string $key): string => (string) $this->dotKey($key),
            array_keys($this->definitions()),
        );

        $rows = Setting::query()
            ->where('group', self::GROUP)
            ->whereIn('key', $dotKeys)
            ->where(fn ($query) => $query->whereNull('company_id')->when($companyId !== null, fn ($scope) => $scope->orWhere('company_id', $companyId)))
            ->get()
            ->groupBy('key');

        $result = [];
        foreach ($this->definitions() as $key => $meta) {
            $value = $this->get($key);
            $keyRows = $rows->get($this->dotKey($key), collect());
            $hasCompany = $companyId !== null && $keyRows->contains(
                fn (Setting $setting): bool => (int) $setting->company_id === $companyId,
            );
            $hasGlobal = $keyRows->contains(fn (Setting $setting): bool => $setting->company_id === null);

            $result[$key] = [
                'value' => $meta['type'] === 'secret' ? '' : $value,
                'configured' => $meta['type'] === 'secret' && $value !== '',
                'in_db' => $hasCompany || $hasGlobal,
                'scope' => $hasCompany ? 'company' : ($hasGlobal ? 'global' : 'config'),
            ] + $meta;
        }

        return $result;
    }

    /** Ключ кэша значений с учётом контекста компании. */
    protected function memoKey(string $fullKey, ?int $companyId): string
    {
        return ($companyId === null ? 'g' : 'c'.$companyId).'|'.$fullKey;
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
