<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Роль Bumi на странице. Роль задаётся маршрутом на сервере: один и тот же
 * Bumi Mini в курсах становится репетитором, в документах — клерком,
 * в финансах — бухгалтером, в аналитике — аналитиком.
 */
enum BumiRole: string
{
    case Tutor = 'tutor';
    case Clerk = 'clerk';
    case Accountant = 'accountant';
    case Analyst = 'analyst';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Tutor => __('репетитор'),
            self::Clerk => __('клерк'),
            self::Accountant => __('бухгалтер'),
            self::Analyst => __('аналитик'),
            self::Assistant => __('помощник'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Tutor => 'ti-brain',
            self::Clerk => 'ti-files',
            self::Accountant => 'ti-calculator',
            self::Analyst => 'ti-chart-bar',
            self::Assistant => 'ti-sparkles',
        };
    }

    public function title(): string
    {
        return __('Bumi · :role', ['role' => $this->label()]);
    }

    public function greeting(): string
    {
        return match ($this) {
            self::Tutor => __('Я тут репетитор. Спроси по уроку — объясню шаг за шагом и проверю, как ты понял.'),
            self::Clerk => __('Я тут клерк. Найду документ, подскажу по срокам и статусу — без догадок.'),
            self::Accountant => __('Я тут бухгалтер. Проверю суммы, сроки и сверку; если данных не хватает — спрошу.'),
            self::Analyst => __('Я тут аналитик. Покажу тренд, разложу метрику и подскажу, что стоит посмотреть'),
            self::Assistant => __('Я на связи. Дай задачу — разберусь по фактам и подскажу следующий шаг.'),
        };
    }

    /**
     * Инструменты роли: каждая кнопка подставляет осмысленный запрос в чат,
     * а не просто декоративная иконка. Ограничение длины — на клиенте и в DTO.
     *
     * @return list<array{label: string, icon: string, prompt: string, mode?: string}>
     */
    public function tools(): array
    {
        return match ($this) {
            self::Tutor => [
                ['label' => __('Объяснить'), 'icon' => 'ti-book', 'prompt' => __('Объясни главное в этом уроке'), 'mode' => 'explain'],
                ['label' => __('Подсказка'), 'icon' => 'ti-bulb', 'prompt' => __('Дай подсказку по уроку'), 'mode' => 'hint'],
                ['label' => __('Проверить'), 'icon' => 'ti-list-check', 'prompt' => __('Проверь, как я понял урок'), 'mode' => 'check'],
            ],
            self::Clerk => [
                ['label' => __('Найти документ'), 'icon' => 'ti-search', 'prompt' => __('Найди документ по этой странице и кратко скажи, что в нём')],
                ['label' => __('Сроки'), 'icon' => 'ti-calendar', 'prompt' => __('Какие сроки и статусы видны на этой странице?')],
                ['label' => __('Что проверить'), 'icon' => 'ti-list-check', 'prompt' => __('Что здесь нужно проверить перед подписанием?')],
            ],
            self::Accountant => [
                ['label' => __('Сверка'), 'icon' => 'ti-calculator', 'prompt' => __('Сверь суммы на странице и скажи, где расхождения')],
                ['label' => __('Просрочки'), 'icon' => 'ti-alert-triangle', 'prompt' => __('Покажи просроченные платежи или начисления')],
                ['label' => __('Сводка'), 'icon' => 'ti-sum', 'prompt' => __('Дай короткую финансовую сводку по странице')],
            ],
            self::Analyst => [
                ['label' => __('Тренд'), 'icon' => 'ti-trending-up', 'prompt' => __('Опиши тренд по текущим данным')],
                ['label' => __('Метрики'), 'icon' => 'ti-chart-dots', 'prompt' => __('Какие метрики здесь главные и как они считаются?')],
                ['label' => __('График'), 'icon' => 'ti-chart-bar', 'prompt' => __('Предложи, какой график лучше показать по этим данным')],
            ],
            self::Assistant => [
                ['label' => __('Задачи на сегодня'), 'icon' => 'ti-list-check', 'prompt' => __('Собери задачи на сегодня')],
                ['label' => __('Кратко'), 'icon' => 'ti-align-left', 'prompt' => __('Кратко перескажи суть этой страницы')],
            ],
        };
    }

    public static function safe(?string $value, self $default = self::Assistant): self
    {
        return self::tryFrom((string) $value) ?? $default;
    }
}
