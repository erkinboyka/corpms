<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\AiConversation;
use App\Models\Employee;

final class BumiTemplates
{
    // Sections are ordered independently for the printed page and for the slide narrative.
    private const EDITIONS = [
        ['Записка руководителю', 'Брифинг руководства', '174C43', 'EBF4EF', 'focus chart table', 'focus chart cards'],
        ['Книга показателей', 'Табло показателей', '294B87', 'EEF3FF', 'cards table', 'cards table'],
        ['Рейтинговый лист', 'Лидеры выборки', '853D21', 'FFF2E6', 'rank table', 'rank focus'],
        ['Аналитический обзор', 'Разбор показателей', '503878', 'F4EFFB', 'chart extremes table', 'chart extremes table'],
        ['Оперативный журнал', 'Оперативное совещание', '244758', 'EDF5F6', 'table cards', 'table cards'],
        ['Паспорт показателя', 'Крупный план', '74324B', 'FFF0F4', 'focus cards', 'focus cards'],
        ['Сравнение значений', 'Два полюса', '303F79', 'F0F2FE', 'extremes rank table', 'extremes rank'],
        ['Визуальная сводка', 'Обзор на экране', '186462', 'ECF8F6', 'chart cards', 'chart cards'],
        ['Контрольная ведомость', 'Сверка данных', '455432', 'F3F6EB', 'table extremes', 'table extremes'],
        ['Атлас данных', 'Атлас команды', '6D4327', 'F9F2E8', 'cards chart table', 'cards chart table'],
        ['Профиль выборки', 'Границы выборки', '653D75', 'F9F1FA', 'extremes cards', 'extremes cards'],
        ['Деловой бюллетень', 'Деловой дайджест', '334860', 'EEF3F8', 'focus rank table', 'focus rank table'],
        ['Компактный реестр', 'Реестр на встрече', '734A14', 'FBF6E6', 'table chart', 'table chart'],
        ['Панель сравнения', 'По убыванию', '1C526F', 'EAF4FA', 'rank cards chart', 'rank chart cards'],
        ['Иллюстрированный обзор', 'Визуальный доклад', '7B3040', 'FCEFF0', 'chart focus table', 'chart focus table'],
    ];

    public const CHARTS = [
        'bars' => 'Горизонтальные полосы', 'columns' => 'Столбцы', 'line' => 'Линия',
        'area' => 'Область', 'step' => 'Ступени', 'donut' => 'Кольцо',
        'stack' => 'Полоса долей', 'dots' => 'Точечная шкала', 'lollipop' => 'Леденцы',
        'heat' => 'Тепловая карта', 'tiles' => 'Карточки значений', 'rank' => 'Ранжированные полосы',
        'bubbles' => 'Круги по величине', 'stems' => 'Вертикальные маркеры', 'pie' => 'Секторы',
    ];

    public static function names(string $kind): array
    {
        return $kind === 'chart' ? array_values(self::CHARTS) : array_column(self::EDITIONS, $kind === 'deck' ? 1 : 0);
    }

    public static function edition(string $kind, int $number): array
    {
        [$report, $deck, $ink, $paper, $reportSections, $slideSections] = self::EDITIONS[$number - 1];

        return ['name' => $kind === 'deck' ? $deck : $report, 'ink' => '#'.$ink, 'paper' => '#'.$paper,
            'sections' => explode(' ', $kind === 'deck' ? $slideSections : $reportSections)];
    }

    /** Zero signals an explicit, unknown template. */
    public static function requested(string $kind, string $question): ?int
    {
        if (preg_match('/шаблон[аеуы]?\s*(?:№|номер|#)?\s*(\d+)\b/iu', $question, $match)) {
            $number = (int) $match[1];

            return $number >= 1 && $number <= count(self::names($kind)) ? $number : 0;
        }
        foreach (self::names($kind) as $index => $name) {
            if (mb_stripos($question, $name) !== false) {
                return $index + 1;
            }
        }

        return null;
    }

    /** Vary automatic choices across consecutive exports of this kind. */
    public static function next(Employee $me, string $kind, array $choices): int
    {
        $recent = AiConversation::query()->where('employee_id', $me->id)->latest('id')->limit(20)->pluck('query_used');
        foreach ($recent as $query) {
            foreach ($query['blocks'] ?? [] as $block) {
                if (($block['artifact'] ?? null) === $kind && isset($block['template'])) {
                    $index = array_search($block['template'], $choices, true);

                    return $index === false ? $choices[0] : $choices[($index + 1) % count($choices)];
                }
            }
        }

        return $choices[0];
    }
}
