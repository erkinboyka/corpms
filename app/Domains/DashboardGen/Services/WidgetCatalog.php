<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

/**
 * Каталог семейств и типов виджетов, доступных ИИ (перенос каталога Datavue).
 *
 * Список параллелен фронтенд-реестру public/assets/js/bi-widgets.js: имя
 * семейства и варианты отрисовки должны совпадать один в один, иначе
 * спецификация не отрисуется.
 */
class WidgetCatalog
{
    /** Вариант по умолчанию — используется и JS, и серверным fallback. */
    public const DEFAULT_TYPES = [
        'mini-counters' => 'cards',
        'bar' => 'column',
        'line' => 'area',
        'pie' => 'pie',
        'radial' => 'gauge',
        'combo' => 'bar-line',
        'table' => 'plain',
        'scatter' => 'scatter',
        'radar' => 'radar',
        'heatmap' => 'discrete',
        'treemap' => 'distributed',
        'funnel' => 'funnel',
    ];

    /**
     * @return list<array{name: string, purpose: string, types: list<array{type: string, when_to_use: string}>}>
     */
    public function families(): array
    {
        return [
            [
                'name' => 'mini-counters',
                'purpose' => 'Плитки-счётчики для общих метрик (KPI): одно число с подписью.',
                'types' => [
                    ['type' => 'cards', 'when_to_use' => 'Карточки с крупным числом; базовый вариант.'],
                    ['type' => 'with-progress', 'when_to_use' => 'Карточки с полосой прогресса (percent 0-100).'],
                ],
            ],
            [
                'name' => 'bar',
                'purpose' => 'Столбчатая диаграмма для сравнения категорий.',
                'types' => [
                    ['type' => 'column', 'when_to_use' => 'Вертикальные столбцы — сравнение категорий по умолчанию.'],
                    ['type' => 'bar', 'when_to_use' => 'Горизонтальные столбцы — длинные подписи категорий.'],
                    ['type' => 'stacked-column', 'when_to_use' => 'Вертикальные столбцы, ряды складываются в столбец.'],
                    ['type' => 'stacked-bar', 'when_to_use' => 'Горизонтальные столбцы, ряды складываются.'],
                    ['type' => 'percent-stacked', 'when_to_use' => 'Доли 0-100%: структура, а не абсолютные значения.'],
                ],
            ],
            [
                'name' => 'line',
                'purpose' => 'Линейный график для динамики во времени.',
                'types' => [
                    ['type' => 'smooth', 'when_to_use' => 'Плавная кривая — общий тренд.'],
                    ['type' => 'straight', 'when_to_use' => 'Прямые отрезки — точные значения без сглаживания.'],
                    ['type' => 'stepline', 'when_to_use' => 'Ступенчатая линия — значения, меняющиеся рывками.'],
                    ['type' => 'area', 'when_to_use' => 'Заливка под линией — акцент на объёме.'],
                    ['type' => 'stacked-area', 'when_to_use' => 'Стопка заливок — вклад частей в целое во времени.'],
                ],
            ],
            [
                'name' => 'pie',
                'purpose' => 'Круговая диаграмма — доли от целого (до 12 сегментов, остальное в «Прочее»).',
                'types' => [
                    ['type' => 'pie', 'when_to_use' => 'Обычный круг.'],
                    ['type' => 'donut', 'when_to_use' => 'Кольцо — в центре можно показать итог.'],
                    ['type' => 'semi-donut', 'when_to_use' => 'Полукольцо — компактный вариант.'],
                ],
            ],
            [
                'name' => 'radial',
                'purpose' => 'Индикатор выполнения (0-100%) — план, заполненность, конверсия.',
                'types' => [
                    ['type' => 'gauge', 'when_to_use' => 'Один полукруглый датчик.'],
                    ['type' => 'multi', 'when_to_use' => 'Несколько круговых датчиков рядом.'],
                ],
            ],
            [
                'name' => 'combo',
                'purpose' => 'Комбинированный график: столбцы + линия в одном (два разных ряда-разреза).',
                'types' => [
                    ['type' => 'bar-line', 'when_to_use' => 'Столбцы и линия; линия обычно для средних/темпов.'],
                    ['type' => 'area-line', 'when_to_use' => 'Заливка и линия.'],
                ],
            ],
            [
                'name' => 'table',
                'purpose' => 'Таблица детальных строк: любые колонки, порядок = порядок SELECT.',
                'types' => [
                    ['type' => 'plain', 'when_to_use' => 'Обычная таблица.'],
                    ['type' => 'striped', 'when_to_use' => 'Через строку — легче читать длинные списки.'],
                    ['type' => 'compact', 'when_to_use' => 'Плотная — больше строк на экране.'],
                ],
            ],
            [
                'name' => 'scatter',
                'purpose' => 'Точечная диаграмма — зависимость двух числовых показателей.',
                'types' => [
                    ['type' => 'scatter', 'when_to_use' => 'Обычные точки, наборы в разных цветах.'],
                    ['type' => 'bubble', 'when_to_use' => 'Пузыри: третья величина — размер точки (колонка z).'],
                ],
            ],
            [
                'name' => 'radar',
                'purpose' => 'Радар/паутинка — сравнение нескольких категорий по одному профилю.',
                'types' => [
                    ['type' => 'radar', 'when_to_use' => 'Обводка линией.'],
                    ['type' => 'filled', 'when_to_use' => 'Залитая область.'],
                    ['type' => 'polar-area', 'when_to_use' => 'Полярная площадь — доли вокруг круга.'],
                ],
            ],
            [
                'name' => 'heatmap',
                'purpose' => 'Тепловая карта — плотность/значение на пересечении двух разрезов.',
                'types' => [
                    ['type' => 'gradient', 'when_to_use' => 'Плавный градиент значений.'],
                    ['type' => 'discrete', 'when_to_use' => 'Ячные категории цветов.'],
                ],
            ],
            [
                'name' => 'treemap',
                'purpose' => 'Дерево-прямоугольники — иерархические доли (топ-N крупных, остальное отсекается).',
                'types' => [
                    ['type' => 'treemap', 'when_to_use' => 'Базовая раскладка.'],
                    ['type' => 'distributed', 'when_to_use' => 'Распределённые прямоугольники — акцент на каждом блоке.'],
                ],
            ],
            [
                'name' => 'funnel',
                'purpose' => 'Воронка — этапы сверху вниз по убыванию (лиды → сделки → оплаты).',
                'types' => [
                    ['type' => 'funnel', 'when_to_use' => 'Классическая трапеция.'],
                    ['type' => 'pyramid', 'when_to_use' => 'Пирамида — зеркальный вариант.'],
                ],
            ],
        ];
    }

    /**
     * Имена семейств, доступных ИИ.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->families(), 'name');
    }

    public function hasFamily(string $family): bool
    {
        return in_array($family, $this->names(), true);
    }

    public function hasType(string $family, ?string $type): bool
    {
        if ($type === null || $type === '') {
            return true;
        }

        foreach ($this->families() as $entry) {
            if ($entry['name'] === $family) {
                return in_array($type, array_column($entry['types'], 'type'), true);
            }
        }

        return false;
    }

    public function defaultType(string $family): ?string
    {
        return self::DEFAULT_TYPES[$family] ?? null;
    }

    /**
     * Серверный fallback для всех семейств: один диспетчер выбирает разметку
     * по форме данных, поэтому новые семейства не остаются пустыми до JS.
     */
    public function ssrView(string $family): ?string
    {
        return $this->hasFamily($family) ? 'bi.widgets.fallback' : null;
    }

    /** @return list<string> */
    public function typesOf(string $family): array
    {
        foreach ($this->families() as $entry) {
            if ($entry['name'] === $family) {
                return array_values(array_column($entry['types'], 'type'));
            }
        }

        return [];
    }

    public function compactJson(): string
    {
        return json_encode($this->families(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
