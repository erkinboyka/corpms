<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\DashboardGen\Services\WidgetCatalog;
use App\Domains\DashboardGen\Services\WidgetQueryRunner;
use App\Models\Company;
use App\Models\CrmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Серверная разметка AI-виджетов: все семейства должны быть читаемы
 * до загрузки ApexCharts, а валидация результата — принимать их формы.
 */
class BiWidgetSsrTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, mixed>> */
    private function contentByFamily(): array
    {
        return [
            'mini-counters' => ['counters' => [
                ['name' => 'Клиенты', 'value' => 12, 'prefix' => '', 'suffix' => '', 'percent' => 60],
            ]],
            'bar' => [
                'series' => [['name' => 'Продажи', 'data' => [10, 20]]],
                'categories' => ['Янв', 'Фев'],
            ],
            'line' => [
                'series' => [['name' => 'Тренд', 'data' => [10, 20]]],
                'labels' => ['Янв', 'Фев'],
            ],
            'pie' => ['series' => [10, 20], 'labels' => ['Янв', 'Фев']],
            'radial' => ['series' => [75], 'labels' => ['Выполнено']],
            'combo' => [
                'series' => [
                    ['name' => 'Продажи', 'data' => [10, 20], 'kind' => 'column'],
                    ['name' => 'План', 'data' => [12, 18], 'kind' => 'line'],
                ],
                'categories' => ['Янв', 'Фев'],
            ],
            'table' => [
                'headers' => ['Клиент', 'Статус'],
                'rows' => [['ООО Ромашка', 'active']],
            ],
            'scatter' => ['series' => [['name' => 'Набор', 'data' => [[1, 2], [3, 4]]]]],
            'radar' => [
                'series' => [['name' => 'Профиль', 'data' => [10, 20]]],
                'categories' => ['Янв', 'Фев'],
            ],
            'heatmap' => ['series' => [['name' => 'Продажи', 'data' => [['x' => 'Янв', 'y' => 10]]]]],
            'treemap' => ['series' => [['data' => [['x' => 'Янв', 'y' => 10]]]]],
            'funnel' => ['series' => [100, 50], 'labels' => ['Лиды', 'Сделки']],
        ];
    }

    public function test_every_catalog_family_has_a_server_side_view(): void
    {
        $catalog = app(WidgetCatalog::class);

        foreach ($catalog->names() as $family) {
            $view = $catalog->ssrView($family);

            self::assertNotNull($view, $family);
            self::assertTrue(view()->exists($view), $view);
            self::assertNotNull($catalog->defaultType($family), $family);
        }
    }

    public function test_fallback_renders_data_for_every_widget_family(): void
    {
        foreach ($this->contentByFamily() as $family => $content) {
            $html = view('bi.widgets.fallback', [
                'family' => $family,
                'type' => null,
                'content' => $content,
                'accent' => '#206bc4',
            ])->render();

            self::assertStringContainsString('data-bi-ssr="'.$family.'"', $html, $family);
            self::assertStringNotContainsString('data-bi-ssr="empty"', $html, $family);
        }

        self::assertStringContainsString('Клиенты', view('bi.widgets.fallback', [
            'family' => 'mini-counters',
            'type' => null,
            'content' => $this->contentByFamily()['mini-counters'],
            'accent' => '#206bc4',
        ])->render());

        self::assertStringContainsString('ООО Ромашка', view('bi.widgets.fallback', [
            'family' => 'table',
            'type' => null,
            'content' => $this->contentByFamily()['table'],
            'accent' => '#206bc4',
        ])->render());
    }

    public function test_fallback_number_format_matches_js_hydration(): void
    {
        $html = view('bi.widgets.fallback', [
            'family' => 'pie',
            'type' => 'pie',
            'content' => ['series' => [1234.5], 'labels' => ['Янв']],
            'accent' => '#206bc4',
        ])->render();

        self::assertStringContainsString('1 234,5', $html);
        self::assertStringNotContainsString('1,234.50', $html);
    }

    public function test_fallback_announces_capped_data_like_js_notice(): void
    {
        $html = view('bi.widgets.fallback', [
            'family' => 'pie',
            'type' => 'pie',
            'content' => [
                'series' => array_fill(0, 13, 1),
                'labels' => array_fill(0, 13, 'Янв'),
            ],
            'accent' => '#206bc4',
        ])->render();

        self::assertStringContainsString('Показано 12 из 13 точек', $html);
    }

    public function test_fallback_escapes_untrusted_labels(): void
    {
        $html = view('bi.widgets.fallback', [
            'family' => 'pie',
            'type' => 'pie',
            'content' => ['series' => [5], 'labels' => ['<script>alert(1)</script>']],
            'accent' => '#206bc4',
        ])->render();

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_radar_polar_area_uses_series_values_shape(): void
    {
        $html = view('bi.widgets.fallback', [
            'family' => 'radar',
            'type' => 'polar-area',
            'content' => ['series' => [75], 'labels' => ['Доля']],
            'accent' => '#206bc4',
        ])->render();

        self::assertStringContainsString('data-bi-ssr-type="polar-area"', $html);
        self::assertStringContainsString('Доля', $html);
    }

    public function test_runner_accepts_radial_treemap_and_heatmap_content(): void
    {
        config()->set('dashboard_gen.tables', ['crm_clients']);

        $company = Company::create(['name' => 'SSR Co', 'code' => 'ssrco']);
        CrmClient::create([
            'company_id' => $company->id,
            'name' => 'Наш клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        $runner = new WidgetQueryRunner(connection: DB::connection(), companyId: (int) $company->id);

        $valuesSql = 'SELECT name AS label, COUNT(*) AS value FROM crm_clients '
            .'WHERE company_id = :company_id GROUP BY name';
        $matrixSql = "SELECT 'Продажи' AS series, name AS category, COUNT(*) AS value FROM crm_clients "
            .'WHERE company_id = :company_id GROUP BY name';

        $cases = [
            ['family' => 'radial', 'shape' => 'series_values', 'sql' => $valuesSql],
            ['family' => 'treemap', 'shape' => 'series_values', 'sql' => $valuesSql],
            ['family' => 'heatmap', 'shape' => 'series_matrix', 'sql' => $matrixSql],
        ];

        foreach ($cases as $case) {
            $result = $runner->run([
                'queries' => ['main' => $case['sql']],
                'shape' => $case['shape'],
            ], $case['family']);

            self::assertTrue($result['ok'], $case['family'].': '.($result['error'] ?? ''));
            self::assertNotEmpty($result['data'], $case['family']);
        }
    }
}
