<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BiDashboard;
use App\Models\BiMetric;
use App\Models\BiWidget;
use App\Models\Company;
use App\Models\User;
use App\Shared\Enums\BiWidgetKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Идемпотентный BI-демо-набор для компании `demo`.
 *
 * Ничего не удаляет и не пересоздаёт: повторный запуск только дополняет
 * отсутствующие демо-строки. Пользовательские правки (фильтры, query_spec,
 * версия, position, последний запуск) остаются нетронутыми.
 */
class BiDemoSeeder extends Seeder
{
    private const DASHBOARD_NAME = 'Демо: Обзор показателей';

    public function __construct(private readonly ?string $companyCode = 'demo')
    {
    }

    public function run(): void
    {
        $company = Company::query()->where('code', $this->companyCode)->first();
        if ($company === null) {
            $this->command?->error('Демо-компания не найдена — сначала выполните DemoSeeder.');

            return;
        }

        DB::transaction(function () use ($company): void {
            $this->metrics($company);
            $dashboard = $this->dashboard($company);
            $this->widgets($dashboard);
        });

        $this->command?->info('BI-демо готово: '.self::DASHBOARD_NAME);
    }

    private function dashboard(Company $company): BiDashboard
    {
        $creatorId = User::query()->where('email', 'admin@corpms.demo')->value('id');

        $dashboard = BiDashboard::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => self::DASHBOARD_NAME],
            [
                'is_default' => false,
                'created_by' => $creatorId,
                'status' => 'ready',
                'origin' => 'manual',
                'version' => 1,
            ],
        );

        // Если старый запуск оставил дубликаты — оставляем самый ранний.
        BiDashboard::query()
            ->where('company_id', $company->id)
            ->where('name', self::DASHBOARD_NAME)
            ->whereKeyNot($dashboard->id)
            ->orderBy('id')
            ->eachById(static fn (BiDashboard $duplicate): ?bool => (bool) $duplicate->delete());

        $repair = [];
        if ($dashboard->status === null || $dashboard->status === '') {
            $repair['status'] = 'ready';
        }
        if ($dashboard->origin === null || $dashboard->origin === '') {
            $repair['origin'] = 'manual';
        }
        if ($dashboard->version === null) {
            $repair['version'] = 1;
        }
        if ($repair !== []) {
            $dashboard->update($repair);
        }

        return $dashboard;
    }

    /** @return list<array{title: string, kind: BiWidgetKind, metric_code: string}> */
    private function widgetDefinitions(): array
    {
        return [
            ['title' => 'Демо: Доходы за период', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'finance_income'],
            ['title' => 'Демо: Расходы за период', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'finance_expense'],
            ['title' => 'Демо: Выигранные сделки', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'deals_won_count'],
            ['title' => 'Демо: Завершено задач', 'kind' => BiWidgetKind::Kpi, 'metric_code' => 'tasks_completed'],
            ['title' => 'Демо: Тренд доходов', 'kind' => BiWidgetKind::Trend, 'metric_code' => 'finance_income'],
            ['title' => 'Демо: Тренд расходов', 'kind' => BiWidgetKind::Trend, 'metric_code' => 'finance_expense'],
        ];
    }

    private function widgets(BiDashboard $dashboard): void
    {
        $definitions = $this->widgetDefinitions();
        $titles = array_column($definitions, 'title');

        foreach ($definitions as $position => $definition) {
            $widget = $dashboard->widgets()->firstOrCreate(
                ['title' => $definition['title']],
                [
                    'kind' => $definition['kind'],
                    'metric_code' => $definition['metric_code'],
                    'position' => $position,
                    'span' => 4,
                    'content_mode' => 'metric',
                    'status' => 'ready',
                ],
            );

            $repair = [];
            if ($widget->kind === null) {
                $repair['kind'] = $definition['kind'];
            }
            if ($widget->metric_code === null || $widget->metric_code === '') {
                $repair['metric_code'] = $definition['metric_code'];
            }
            if ($widget->content_mode === null || $widget->content_mode === '') {
                $repair['content_mode'] = 'metric';
            }
            if ($widget->status === null || $widget->status === '') {
                $repair['status'] = 'ready';
            }
            if ($repair !== []) {
                $widget->update($repair);
            }
        }

        // Схлопываем дубликаты демо-виджетов, но не трогаем пользовательские.
        $dashboard->widgets()
            ->whereIn('title', $titles)
            ->orderBy('id')
            ->get()
            ->skip(count($titles))
            ->each(static fn (BiWidget $widget): ?bool => (bool) $widget->delete());
    }

    private function metrics(Company $company): void
    {
        $codes = ['finance_income', 'finance_expense', 'deals_won_count', 'tasks_completed'];

        for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
            $day = CarbonImmutable::today()->subDays($daysAgo);
            $date = $day->toDateString();
            // cast 'date' сохраняет полноту до секунд; поиск должен использовать
            // то же представление, иначе SQLite считает строки разными.
            $metricDate = $day->startOfDay()->toDateTimeString();

            foreach ($codes as $code) {
                $seed = crc32($code.'|'.$date);
                $value = round(1000 + (13 - $daysAgo) * 25 + ($seed % 400), 2);

                BiMetric::query()->updateOrCreate(
                    ['company_id' => $company->id, 'metric_date' => $metricDate, 'code' => $code],
                    ['value' => $value],
                );
            }
        }
    }
}
