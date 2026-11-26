<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Bi\Services\BiService;
use App\Models\BiDashboard;
use App\Models\BiMetric;
use App\Models\BiWidget;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\BiDemoSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Идемпотентность BI-демо: повторный seeder не изменяет уже созданное. */
class BiDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedDemo();
        $this->seed(BiDemoSeeder::class);

        $company = Company::query()->where('code', 'demo')->sole();
        $dashboard = BiDashboard::query()->where('company_id', $company->id)->where('name', 'Демо: Обзор показателей')->sole();

        $beforeWidgets = $dashboard->widgets()->orderBy('position')->get(['id', 'title', 'position'])->toArray();
        $metricCount = BiMetric::query()->where('company_id', $company->id)->count();
        $dashboardCount = BiDashboard::query()->where('company_id', $company->id)->count();

        $this->seed(BiDemoSeeder::class);

        self::assertSame($dashboard->id, BiDashboard::query()->where('company_id', $company->id)->where('name', 'Демо: Обзор показателей')->sole()->id);
        self::assertSame($dashboardCount, BiDashboard::query()->where('company_id', $company->id)->count());
        self::assertSame($beforeWidgets, $dashboard->widgets()->orderBy('position')->get(['id', 'title', 'position'])->toArray());
        self::assertSame($metricCount, BiMetric::query()->where('company_id', $company->id)->count());

        self::assertFalse((bool) $dashboard->fresh()->is_default);
        self::assertSame('manual', $dashboard->fresh()->origin);
        self::assertSame('ready', $dashboard->fresh()->status);
    }

    public function test_seeder_does_not_touch_default_dashboard_or_user_edits(): void
    {
        $this->seedDemo();

        $company = Company::query()->where('code', 'demo')->sole();
        $creatorId = User::query()->where('email', 'admin@corpms.demo')->value('id');
        $service = app(BiService::class);
        $default = $service->ensureDefaultDashboard((int) $company->id, (int) $creatorId);
        $custom = $default->widgets()->create([
            'title' => 'Пользовательский виджет',
            'kind' => 'kpi',
            'metric_code' => 'headcount',
            'position' => 50,
            'filters' => ['period' => 'last-quarter'],
        ]);

        $this->seed(BiDemoSeeder::class);
        $this->seed(BiDemoSeeder::class);

        self::assertSame(7, $default->fresh()->widgets()->count());
        self::assertSame('Пользовательский виджет', $custom->fresh()->title);
        self::assertSame(['period' => 'last-quarter'], $custom->fresh()->filters);
        self::assertTrue((bool) $default->fresh()->is_default);
    }

    public function test_seeder_is_a_noop_without_the_demo_company(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(BiDemoSeeder::class);

        self::assertSame(0, BiDashboard::query()->count());
        self::assertSame(0, BiWidget::query()->count());
        self::assertSame(0, BiMetric::query()->count());
    }

    public function test_seeder_is_tenant_scoped(): void
    {
        $this->seedDemo();
        $this->seed(BiDemoSeeder::class);

        $other = Company::create(['name' => 'Other Co', 'code' => 'other']);

        self::assertSame(0, BiDashboard::query()->where('company_id', $other->id)->count());
        self::assertSame(0, BiMetric::query()->where('company_id', $other->id)->count());
    }
}
