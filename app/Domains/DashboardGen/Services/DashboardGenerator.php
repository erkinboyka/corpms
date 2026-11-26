<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Оркестратор генерации дашборда (перенос DashboardGenerator из Datavue):
 *
 *   схема белого списка → план ИИ → каркас виджетов → SQL-спецификация
 *   каждого виджета с валидацией и ремонтом → статусы дашборда/виджетов.
 *
 * Вызывается из очереди (GenerateAiDashboard); в случае фатальной ошибки
 * бросает исключение — job помечает дашборд status=error.
 */
class DashboardGenerator
{
    public function __construct(
        private AiClient $ai,
        private DashboardPlanAi $planAi,
        private WidgetCatalog $catalog,
        private SchemaInspector $inspector,
    ) {
    }

    public function generate(BiDashboard $dashboard, string $instruction, int $companyId): void
    {
        $tables = array_values(array_filter(
            (array) config('dashboard_gen.tables', []),
            'is_string',
        ));

        $schema = $this->inspector->schema($tables);

        if ($schema === []) {
            throw new RuntimeException('Не удалось получить схему таблиц для генерации.');
        }

        $plan = $this->planAi->plan($instruction, $schema, $this->catalog->compactJson());

        if (!$plan['ok'] || !is_array($plan['plan'])) {
            throw new RuntimeException($plan['error'] ?? 'ИИ не смог составить план дашборда.');
        }

        $planData = $plan['plan'];
        $name = trim((string) ($planData['dashboard_name'] ?? ''));
        $name = $name !== '' ? Str::limit($name, 255, '') : Str::limit($instruction, 255, '');

        $oldSqlIds = $dashboard->widgets()->where('content_mode', 'sql')->pluck('id')->all();

        $dashboard->update([
            'name' => $name,
            'instruction' => $instruction,
            'status' => 'generating',
            'last_error' => null,
        ]);

        $widgets = $this->createFromPlan($dashboard, $planData['widgets'], $schema);

        if ($widgets === []) {
            throw new RuntimeException('ИИ не вернул ни одного подходящего виджета.');
        }

        $driver = DB::connection()->getDriverName();

        $ready = 0;

        foreach ($widgets as $entry) {
            $widget = $entry['widget'];

            // Каждый виджет получает только свои таблицы: полная схема на 30+
            // таблиц упирается в лимит токенов провайдера и ломает генерацию.
            $generator = new WidgetSpecGenerator(
                $this->ai,
                $driver,
                $this->schemaFor($entry['tables'], $schema),
                $companyId,
                $schema,
            );

            $result = $generator->generate(
                (string) $widget->instruction,
                (string) $widget->widget_family,
                $widget->widget_type,
            );

            if ($result['ok']) {
                $widget->update([
                    'query_spec' => $result['spec'],
                    'status' => 'ready',
                    'last_error' => null,
                    'last_run_at' => now(),
                ]);
                $ready++;
            } else {
                $widget->update([
                    'status' => 'error',
                    'last_error' => Str::limit((string) $result['error'], 1000, ''),
                ]);
            }
        }

        $dashboard->refresh();

        if ($ready > 0 && $oldSqlIds !== []) {
            $dashboard->widgets()->whereIn('id', $oldSqlIds)->delete();
        } elseif ($ready === 0 && $oldSqlIds !== []) {
            // сохраняем старые SQL-виджеты, удаляем неудавшуюся новую попытку
            $newIds = array_map(static fn (array $entry): int => (int) $entry['widget']->id, $widgets);
            if ($newIds !== []) {
                $dashboard->widgets()->whereIn('id', $newIds)->delete();
            }
        }

        $dashboard->update([
            'status' => $ready > 0 ? 'ready' : 'error',
            'last_error' => $ready > 0
                ? null
                : 'Не удалось построить ни один запрос виджета.',
            'version' => (int) $dashboard->version + 1,
        ]);
    }

    /**
     * Каркас виджетов из плана: только семейства из каталога, уникальные
     * заголовки, потолок max_widgets.
     *
     * @param  list<mixed>  $planWidgets
     * @param  array<string, array<string, array{type: string, samples?: list<string>}>>  $schema
     * @return list<array{widget: BiWidget, tables: list<string>}>
     */
    private function createFromPlan(BiDashboard $dashboard, array $planWidgets, array $schema): array
    {
        $max = max(1, (int) config('dashboard_gen.max_widgets', 12));
        $created = [];
        $titles = [];
        $position = (int) $dashboard->widgets()->max('position');

        foreach ($planWidgets as $entry) {
            if (count($created) >= $max) {
                break;
            }

            if (!is_array($entry)) {
                continue;
            }

            $family = trim((string) ($entry['name'] ?? ''));

            if (!$this->catalog->hasFamily($family)) {
                continue;
            }

            $type = $entry['type'] ?? null;
            $type = is_string($type) && $type !== '' ? $type : null;

            if ($type !== null && !$this->catalog->hasType($family, $type)) {
                $type = null;
            }

            if ($type === null) {
                $types = $this->catalog->typesOf($family);
                $type = is_string($types[0] ?? null) ? $types[0] : null;
            }

            $title = trim((string) ($entry['title'] ?? ''));

            if ($title === '') {
                $title = $family;
            }

            if (in_array($title, $titles, true)) {
                $title = $title.' '.(count($titles) + 1);
            }

            $titles[] = $title;

            $instruction = trim((string) ($entry['instruction'] ?? ''));

            if ($instruction === '') {
                $instruction = $title;
            }

            $tables = array_values(array_filter(
                is_array($entry['tables'] ?? null) ? $entry['tables'] : [],
                static fn (mixed $table): bool => is_string($table) && isset($schema[$table]),
            ));

            $position++;

            $created[] = [
                'widget' => $dashboard->widgets()->create([
                    'title' => Str::limit($title, 255, ''),
                    'kind' => 'kpi',
                    'metric_code' => null,
                    'position' => $position,
                    'widget_family' => $family,
                    'widget_type' => $type,
                    'content_mode' => 'sql',
                    'span' => $this->spanFor($entry, $family),
                    'instruction' => $instruction,
                    'status' => 'pending',
                ]),
                'tables' => array_slice($tables, 0, 4),
            ];
        }

        return $created;
    }

    /**
     * Ширина виджета: явное значение из плана, иначе разумный дефолт по
     * семейству. Никакого «всё по 4» — у таблиц и карт должна быть строка.
     *
     * @param  array<string, mixed>  $entry
     */
    private function spanFor(array $entry, string $family): int
    {
        $span = $entry['span'] ?? null;

        if (is_numeric($span) && in_array((int) $span, [4, 6, 12], true)) {
            return (int) $span;
        }

        $defaults = [
            'mini-counters' => 6,
            'table' => 12,
            'heatmap' => 12,
            'treemap' => 12,
            'bar' => 6,
            'line' => 6,
            'combo' => 6,
            'scatter' => 6,
            'radar' => 6,
            'funnel' => 6,
            'pie' => 4,
            'radial' => 4,
        ];

        return $defaults[$family] ?? 4;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, array{type: string, samples?: list<string>}>>  $schema
     * @return array<string, array<string, array{type: string, samples?: list<string>}>>
     */
    private function schemaFor(array $tables, array $schema): array
    {
        if ($tables === []) {
            $tables = array_slice(array_keys($schema), 0, 3);
        }

        $result = [];

        foreach ($tables as $table) {
            if (isset($schema[$table])) {
                $result[$table] = $schema[$table];
            }
        }

        return $result;
    }
}
