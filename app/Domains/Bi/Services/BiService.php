<?php

declare(strict_types=1);

namespace App\Domains\Bi\Services;

use App\Models\BiDashboard;
use App\Models\BiMetric;
use App\Models\BiWidget;
use App\Models\CrmDeal;
use App\Models\Employee;
use App\Models\FinPayment;
use App\Models\FinTransaction;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\Task;
use App\Shared\Enums\BiWidgetKind;
use App\Shared\Enums\CrmDealStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * BI-движок (Этап E6): дневная витрина метрик, срезы по периодам,
 * кастомные дашборды с KPI/трендами и прогноз по линейному тренду.
 *
 * Правила:
 *   - витрина собирается из источников (финансы, CRM, задачи, склад, проекты)
 *     и хранится в bi_metrics по дням; повторная сборка периода перезаписывает его;
 *   - «event»-метрики суммируются по периоду, «snapshot»-метрики берут последнее значение;
 *   - прогноз — линейная регрессия по последним 6 месяцам метрики, без ML-зависимостей.
 */
class BiService
{
    /**
     * Каталог метрик витрины: подпись, единица и способ агрегации.
     *
     * @var array<string, array{label:string, unit:string, kind:string}>
     */
    public const METRICS = [
        'finance_income' => ['label' => 'Доходы', 'unit' => 'money', 'kind' => 'event'],
        'finance_expense' => ['label' => 'Расходы', 'unit' => 'money', 'kind' => 'event'],
        'deals_won_count' => ['label' => 'Выигранные сделки', 'unit' => 'count', 'kind' => 'event'],
        'deals_won_amount' => ['label' => 'Сумма выигранных сделок', 'unit' => 'money', 'kind' => 'event'],
        'invoices_paid' => ['label' => 'Оплаты по счетам', 'unit' => 'money', 'kind' => 'event'],
        'tasks_completed' => ['label' => 'Завершено задач', 'unit' => 'count', 'kind' => 'event'],
        'headcount' => ['label' => 'Штат', 'unit' => 'count', 'kind' => 'snapshot'],
        'projects_active' => ['label' => 'Активные проекты', 'unit' => 'count', 'kind' => 'snapshot'],
        'inventory_value' => ['label' => 'Стоимость запасов', 'unit' => 'money', 'kind' => 'snapshot'],
    ];

    /**
     * @return array<string, array{label:string, unit:string, kind:string}>
     */
    public function catalog(): array
    {
        return self::METRICS;
    }

    /**
     * @return array{label:string, unit:string, kind:string}
     */
    public function metricMeta(string $code): array
    {
        return self::METRICS[$code] ?? ['label' => $code, 'unit' => 'count', 'kind' => 'event'];
    }

    /**
     * Сборка витрины за период: событийные метрики по дням, снапшоты — на конец периода.
     *
     * @return int сколько строк витрины записано
     */
    public function rebuild(int $companyId, string $from, string $to): int
    {
        $from = CarbonImmutable::parse($from)->toDateString();
        $to = CarbonImmutable::parse($to)->toDateString();

        BiMetric::query()
            ->where('company_id', $companyId)
            ->whereBetween('metric_date', [$from, $to])
            ->delete();

        $daily = [];

        $add = static function (string $code, mixed $date, mixed $value) use (&$daily): void {
            if ($date === null) {
                return;
            }

            $key = is_string($date) ? substr($date, 0, 10) : CarbonImmutable::parse($date)->toDateString();
            $daily[$key][$code] = (float) $value;
        };

        $income = FinTransaction::query()
            ->where('company_id', $companyId)
            ->where('direction', 'income')
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('occurred_at as d, SUM(amount) as v')
            ->groupBy('occurred_at')
            ->get();

        foreach ($income as $row) {
            $add('finance_income', $row->d, $row->v);
        }

        $expense = FinTransaction::query()
            ->where('company_id', $companyId)
            ->where('direction', 'expense')
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('occurred_at as d, SUM(amount) as v')
            ->groupBy('occurred_at')
            ->get();

        foreach ($expense as $row) {
            $add('finance_expense', $row->d, $row->v);
        }

        $won = CrmDeal::query()
            ->where('company_id', $companyId)
            ->where('stage', CrmDealStage::Won->value)
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('closed_at as d, COUNT(*) as c, SUM(amount) as v')
            ->groupBy('closed_at')
            ->get();

        foreach ($won as $row) {
            $add('deals_won_count', $row->d, $row->c);
            $add('deals_won_amount', $row->d, $row->v);
        }

        $paid = FinPayment::query()
            ->where('company_id', $companyId)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('paid_at as d, SUM(amount) as v')
            ->groupBy('paid_at')
            ->get();

        foreach ($paid as $row) {
            $add('invoices_paid', $row->d, $row->v);
        }

        $completed = Task::query()
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereRaw('DATE(completed_at) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('DATE(completed_at) as d, COUNT(*) as c')
            ->groupByRaw('DATE(completed_at)')
            ->get();

        foreach ($completed as $row) {
            $add('tasks_completed', $row->d, $row->c);
        }

        // Снапшоты состояния — на дату сборки внутри периода (для текущего месяца — сегодня).
        $snapshotDate = CarbonImmutable::now()->toDateString();

        if ($snapshotDate < $from || $snapshotDate > $to) {
            $snapshotDate = $to;
        }

        $daily[$snapshotDate]['headcount'] = (float) Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->count();

        $daily[$snapshotDate]['projects_active'] = (float) Project::query()
            ->where('company_id', $companyId)
            ->open()
            ->count();

        $daily[$snapshotDate]['inventory_value'] = $this->inventoryValue($companyId);

        $written = 0;

        foreach ($daily as $date => $metrics) {
            foreach ($metrics as $code => $value) {
                BiMetric::query()->updateOrCreate(
                    ['company_id' => $companyId, 'metric_date' => $date, 'code' => $code],
                    ['value' => $value],
                );
                $written++;
            }
        }

        return $written;
    }

    /**
     * Итоги всех метрик за период (event — сумма, snapshot — последнее значение).
     *
     * @return array<string, float>
     */
    public function totals(int $companyId, string $from, string $to): array
    {
        $rows = BiMetric::query()
            ->where('company_id', $companyId)
            ->whereBetween('metric_date', [$from, $to])
            ->get()
            ->groupBy('code');

        $totals = [];

        foreach (self::METRICS as $code => $meta) {
            /** @var Collection<int, BiMetric> $values */
            $values = $rows->get($code) ?? collect();

            $totals[$code] = $meta['kind'] === 'event'
                ? round((float) $values->sum('value'), 2)
                : round((float) ($values->sortBy('metric_date')->last()?->value ?? 0), 2);
        }

        return $totals;
    }

    /**
     * Дневной ряд метрики: ['Y-m-d' => value].
     *
     * @return Collection<string, float>
     */
    public function series(int $companyId, string $code, string $from, string $to): Collection
    {
        return BiMetric::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->whereBetween('metric_date', [$from, $to])
            ->orderBy('metric_date')
            ->get()
            ->mapWithKeys(static fn (BiMetric $row): array => [$row->metric_date->toDateString() => (float) $row->value]);
    }

    /**
     * Срез метрики за период.
     *
     * @return array<string, int|float>
     */
    public function summary(int $companyId, string $code, string $from, string $to): array
    {
        $values = $this->series($companyId, $code, $from, $to)->values();

        return [
            'sum' => round((float) $values->sum(), 2),
            'avg' => $values->isEmpty() ? 0.0 : round((float) $values->avg(), 2),
            'min' => $values->isEmpty() ? 0.0 : round((float) $values->min(), 2),
            'max' => $values->isEmpty() ? 0.0 : round((float) $values->max(), 2),
            'days' => $values->count(),
        ];
    }

    /**
     * Прогноз метрики на N месяцев вперёд по линейному тренду последних месяцев.
     *
     * @return array<int, array{month:string, value:float}>
     */
    public function forecast(int $companyId, string $code, int $months = 3): array
    {
        $rows = BiMetric::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->orderBy('metric_date')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $monthly = [];

        foreach ($rows as $row) {
            $key = $row->metric_date->format('Y-m');
            $monthly[$key] = ($monthly[$key] ?? 0) + (float) $row->value;
        }

        ksort($monthly);
        $monthly = array_slice($monthly, -6, null, true);

        if (count($monthly) < 2) {
            return [];
        }

        $ys = array_values($monthly);
        $n = count($ys);
        $xSum = 0;
        $ySum = 0.0;
        $xySum = 0.0;
        $xxSum = 0;

        foreach ($ys as $index => $value) {
            $xSum += $index;
            $ySum += $value;
            $xySum += $index * $value;
            $xxSum += $index * $index;
        }

        $denominator = $n * $xxSum - $xSum * $xSum;
        $slope = $denominator === 0 ? 0.0 : ($n * $xySum - $xSum * $ySum) / $denominator;
        $intercept = ($ySum - $slope * $xSum) / $n;

        $lastMonth = CarbonImmutable::createFromFormat('Y-m', array_key_last($monthly))->startOfMonth();
        $forecast = [];

        for ($i = 1; $i <= $months; $i++) {
            $x = $n - 1 + $i;
            $forecast[] = [
                'month' => $lastMonth->addMonths($i)->format('Y-m'),
                'value' => round(max(0.0, $intercept + $slope * $x), 2),
            ];
        }

        return $forecast;
    }

    /**
     * Дашборд по умолчанию: создаётся при первом открытии с базовыми виджетами.
     */
    public function ensureDefaultDashboard(int $companyId, ?int $userId = null): BiDashboard
    {
        $dashboard = BiDashboard::query()->firstOrCreate(
            ['company_id' => $companyId, 'is_default' => true],
            ['name' => 'Обзор', 'created_by' => $userId],
        );

        if ($dashboard->widgets()->count() === 0) {
            $widgets = [
                ['title' => 'Доходы за период', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'finance_income'],
                ['title' => 'Расходы за период', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'finance_expense'],
                ['title' => 'Выигранные сделки', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'deals_won_amount'],
                ['title' => 'Завершено задач', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'tasks_completed'],
                ['title' => 'Тренд доходов', 'kind' => BiWidgetKind::Trend, 'metric_code' => 'finance_income'],
                ['title' => 'Тренд расходов', 'kind' => BiWidgetKind::Trend, 'metric_code' => 'finance_expense'],
            ];

            foreach ($widgets as $position => $data) {
                $dashboard->widgets()->create([...$data, 'position' => $position]);
            }
        }

        return $dashboard;
    }

    /**
     * Данные виджета за период.
     *
     * @return array<string, mixed>
     */
    public function widgetData(BiWidget $widget, int $companyId, string $from, string $to): array
    {
        $meta = $this->metricMeta($widget->metric_code);

        if ($widget->kind === BiWidgetKind::Trend) {
            $series = $this->series($companyId, $widget->metric_code, $from, $to);

            return [
                'meta' => $meta,
                'series' => $series,
                'summary' => $this->summary($companyId, $widget->metric_code, $from, $to),
                'points' => $this->sparklinePoints($series),
            ];
        }

        return [
            'meta' => $meta,
            'value' => $this->totals($companyId, $from, $to)[$widget->metric_code] ?? 0.0,
        ];
    }

    /**
     * SVG-точки для спарклайна тренда (без JS-библиотек).
     *
     * @param  Collection<string, float>  $series
     */
    public function sparklinePoints(Collection $series, float $width = 240.0, float $height = 48.0): string
    {
        $values = $series->values();

        if ($values->count() < 2) {
            return '';
        }

        $min = (float) $values->min();
        $max = (float) $values->max();
        $range = $max - $min;
        $step = $width / ($values->count() - 1);
        $points = [];

        foreach ($values as $index => $value) {
            $x = round($index * $step, 1);
            $y = $range > 0
                ? round($height - (((float) $value - $min) / $range) * $height, 1)
                : round($height / 2, 1);
            $points[] = $x.','.$y;
        }

        return implode(' ', $points);
    }

    /** Текущая стоимость запасов компании (остаток × закупочная цена). */
    private function inventoryValue(int $companyId): float
    {
        $stock = StockMovement::query()
            ->where('company_id', $companyId)
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return round((float) Product::query()
            ->where('company_id', $companyId)
            ->get()
            ->sum(static fn (Product $product): float => (float) ($stock[$product->id] ?? 0) * (float) $product->price), 2);
    }
}
