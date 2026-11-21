<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Analytics\Services\AnalyticsService;
use App\Models\Employee;

final class BumiArtifacts
{
    public function __construct(private AnalyticsService $analytics, private BumiFiles $files) {}

    public function prepare(Employee $me, string $object, array $facts): ?array
    {
        $kind = match (true) {
            (bool) preg_match('/презентаци|слайды?\b|pptx\b/iu', $object) => 'deck',
            (bool) preg_match('/csv\b/iu', $object) => 'csv',
            (bool) preg_match('/отч[её]т/iu', $object) => 'report',
            (bool) preg_match('/диаграмм|график|svg\b/iu', $object) => 'chart',
            (bool) preg_match('/таблиц/iu', $object) => 'csv',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $requested = BumiTemplates::requested($kind, $object);
        if ($requested === 0) {
            return ['reply' => __('Выберите номер шаблона от 1 до 15.'), 'blocks' => [['type' => 'templates']]];
        }
        $object = trim(preg_replace('/шаблон[аеуы]?\s*(?:№|номер|#)?\s*\d+/iu', '', $object));
        $dataset = $this->dataset($me, $object, $facts);
        if (isset($dataset['reply'])) {
            return $dataset + ['blocks' => []];
        }
        [$title, $values, $registers, $period, $chronological] = $dataset;
        if (! $values) {
            return ['reply' => __('В вашей выборке нет значений для этого материала.'), 'blocks' => []];
        }
        $source = BumiSources::snapshot($me, $registers, $facts, $period);
        $style = $chronological ? 'line' : 'bars';
        if ($kind === 'chart') {
            $hint = match (true) {
                (bool) preg_match('/кругов|кольцев/iu', $object) => 'donut',
                (bool) preg_match('/линейн|тренд/iu', $object) => 'line',
                (bool) preg_match('/столбчат/iu', $object) => 'columns',
                default => null,
            };
            $styles = array_keys(BumiTemplates::CHARTS);
            $choices = $chronological ? [3, 4, 5, 2, 14] : [1, 2, 8, 9, 10, 11, 12, 13, 14];
            $number = $requested ?? ($hint ? array_search($hint, $styles, true) + 1 : BumiTemplates::next($me, $kind, $choices));
            $style = $styles[$number - 1];
            if (in_array($style, ['donut','pie','stack'], true) && (min($values) < 0 || array_sum($values) <= 0 || count($registers) > 1)) {
                return ['reply' => 'Диаграмма долей требует сопоставимых неотрицательных величин с положительной суммой. Для этой выборки подойдут «Столбцы» или «Точечная шкала».', 'blocks' => [['type' => 'templates']]];
            }
        } else {
            $number = $kind === 'csv' ? 1 : ($requested ?? BumiTemplates::next($me, $kind, range(1, 15)));
        }

        return $this->files->artifact($me, $title, $values, $kind, $number, $style, $source);
    }

    private function dataset(Employee $me, string $object, array $facts): array
    {
        $input = preg_replace('/^.*?(?:по данным|данные|диаграмму|график|таблицу|презентацию|отч[её]т)\s*[: ]*/iu', '', $object);
        if (preg_match('/(?:по данным|данные)\s*:?\s*(.+)$/ius', $object, $match)) {
            $input = $match[1];
        }
        if (preg_match_all('/([\p{L}][\p{L}\d _-]{0,60})\s*[:=]\s*(-?\d+(?:[.,]\d+)?)/u', $input, $pairs, PREG_SET_ORDER)) {
            $values = [];
            foreach ($pairs as $pair) {
                $label = trim($pair[1]);
                if (array_key_exists($label, $values)) {
                    return ['reply' => __('У нескольких значений одинаковая подпись «').$label.__('». Уточните подписи, чтобы сохранить каждое значение.')];
                }
                $values[$label] = (float) str_replace(',', '.', $pair[2]);
            }
            return [__('Показатели из сообщения'), $values, ['message'], null, (bool) preg_match('/январ|феврал|март|апрел|июн|июл|август|сентябр|октябр|ноябр|декабр/iu', $input)];
        }

        $attendance = (bool) preg_match('/посещ|приход|опозд|явк/iu', $object);
        if ($attendance && preg_match('/за (?:последнюю )?неделю|за (?:последний )?месяц|за (?:последние )?(\d+) (?:дней|дня|день)\b/iu', $object, $period)) {
            $days = isset($period[1]) ? (int) $period[1] : (mb_stripos($period[0], __('неделю')) !== false ? 7 : 30);
            if ($days < 1 || $days > 90) {
                return ['reply' => __('Укажите период от 1 до 90 дней.')];
            }
            $people = isset($facts['attendance_today']) ? $this->analytics->accessibleEmployees($me->user, 'analytics.view')['employees'] : collect([$me]);
            $late = (bool) preg_match('/опозд/iu', $object);
            $values = [];
            foreach ($this->analytics->attendanceTrend($people, $days) as $day) {
                $values[$day['date']->format('d.m')] = $late ? $day['late'] : $day['present'] + $day['late'] + $day['remote'];
            }
            return [($late ? __('Опоздания') : __('Отмеченные сотрудники')).' за '.$days.' дн.', $values, ['attendance'],
                today()->subDays($days - 1)->format('d.m.Y').' — '.today()->format('d.m.Y'), true];
        }
        if (preg_match('/вчера|месяц|недел|квартал|20\d{2}|продаж|выручк|расход/iu', $object)) {
            return ['reply' => 'Для этой выборки пришлите значения, например «по данным: январь=12, февраль=18». Из журнала посещаемости можно собрать последние 1–90 дней.'];
        }
        if ($attendance) {
            $a = $facts['attendance_today'] ?? [];
            $values = $a ? [__('На месте') => $a['present'], __('Опоздали') => $a['late'], __('Удалённо') => $a['remote'], __('Без отметок') => $a['absent_from_schedule']] : [];
            if (is_array($facts['my_attendance_today'] ?? null)) {
                $values = [__('Опоздание, мин') => $facts['my_attendance_today']['late_minutes']];
            }
            return ['Посещаемость за '.today()->format('d.m.Y'), $values, ['attendance'], __('Сегодня'), false];
        }
        if (preg_match('/задач/iu', $object)) {
            $labels = ['new'=>__('Новые'),'planned'=>__('Запланированы'),'in_progress'=>__('В работе'),'review'=>__('На проверке'),'completed'=>__('Завершены'),'overdue'=>__('Просрочены'),'returned'=>__('Возвращены'),'cancelled'=>__('Отменены')];
            $values = [];
            foreach ($facts['tasks'] ?? $facts['my_tasks'] ?? [] as $status => $count) {
                $values[$labels[$status] ?? $status] = $count;
            }
            return [__('Задачи по статусам'), $values ?: [__('Всего задач') => 0], ['tasks'], null, false];
        }
        if (preg_match('/отпуск|отсутстви/iu', $object)) {
            $stats = $facts['leaves'] ?? $this->analytics->leaveStats(collect([$me]));
            return [__('Заявки на отсутствие'), [__('На согласовании') => $stats['pending'] ?? 0, __('Согласованы') => $stats['approved'] ?? 0, __('Отклонены') => $stats['rejected'] ?? 0], ['leaves'], null, false];
        }
        if (preg_match('/числен|сотрудник|отдел|кадр/iu', $object)) {
            if (!isset($facts['headcount'])) {
                return ['reply' => __('Для разбивки численности нужен доступ к кадровой аналитике.')];
            }
            $people = $this->analytics->accessibleEmployees($me->user, 'analytics.view')['employees'];
            $values = array_column($this->analytics->hrByDepartment($people), 'headcount', 'name');
            return [__('Численность по подразделениям'), $values, ['headcount'], null, false];
        }
        if (preg_match('/\b(?:по|про|об|о|на тему)\s+(?!компани|работ|показател|данным|шаблон|итог)/iu', $object)) {
            return ['reply' => 'Какие данные взять для этой темы? Укажите показатели с числами или выберите задачи, посещаемость, численность или отпуска.'];
        }
        $values = [];
        $registers = [];
        if (isset($facts['headcount'])) {
            $values[__('Активные сотрудники')] = $facts['headcount']['active'];
            $values[__('На месте сегодня')] = $facts['attendance_today']['present'];
            $registers = ['headcount', 'attendance'];
        }
        $values[__('Просроченные задачи')] = $facts['tasks']['overdue'] ?? $facts['my_tasks']['overdue'] ?? 0;
        return [__('Сводка по рабочим показателям'), $values, [...$registers, 'tasks'], null, false];
    }
}
