<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\BiWidgetKind;
use App\Support\TenantGuard;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Явные company-скоупы, печать company_id и первые BI-политики. */
final class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;

    private Company $other;

    private User $user;

    private Employee $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->mine = Company::create(['name' => 'Mine', 'code' => 'mine']);
        $this->other = Company::create(['name' => 'Other', 'code' => 'other']);
        $this->user = User::factory()->create(['email' => 'tenant-scope@corpms.demo']);
        $this->me = Employee::create([
            'company_id' => $this->mine->id,
            'user_id' => $this->user->id,
            'employee_no' => 'TS-1',
            'first_name' => 'Tenant',
            'last_name' => 'Scope',
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        EmployeeRole::create([
            'employee_id' => $this->me->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->user);
    }

    private function dashboard(Company $company, string $name): BiDashboard
    {
        return BiDashboard::create([
            'company_id' => $company->id,
            'name' => $name,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_dashboard_scope_uses_current_or_explicit_company(): void
    {
        $mine = $this->dashboard($this->mine, 'Мой дашборд');
        $foreign = $this->dashboard($this->other, 'Чужой дашборд');

        self::assertSame(
            [$mine->id],
            BiDashboard::query()->activeCompany()->pluck('id')->all(),
        );
        self::assertSame(
            [$foreign->id],
            BiDashboard::query()->forCompany((int) $this->other->id)->pluck('id')->all(),
        );
    }

    public function test_dashboard_stamps_company_from_authenticated_employee(): void
    {
        $dashboard = BiDashboard::create([
            'name' => 'Без company_id',
            'created_by' => $this->user->id,
        ]);

        self::assertSame($this->mine->id, (int) $dashboard->company_id);
    }

    public function test_widget_scope_goes_through_dashboard(): void
    {
        $mine = $this->dashboard($this->mine, 'Мой');
        $foreign = $this->dashboard($this->other, 'Чужой');

        $ownWidget = BiWidget::create([
            'dashboard_id' => $mine->id,
            'title' => 'Мой виджет',
            'kind' => BiWidgetKind::Kpi,
            'position' => 0,
        ]);
        $foreignWidget = BiWidget::create([
            'dashboard_id' => $foreign->id,
            'title' => 'Чужой виджет',
            'kind' => BiWidgetKind::Kpi,
            'position' => 0,
        ]);

        self::assertSame(
            [$ownWidget->id],
            BiWidget::query()->forCompany()->pluck('id')->all(),
        );
        self::assertTrue(TenantGuard::owns($this->me, $ownWidget, ['dashboard']));
        self::assertFalse(TenantGuard::owns($this->me, $foreignWidget, ['dashboard']));
    }

    public function test_policies_combine_permission_and_tenant_ownership(): void
    {
        $mine = $this->dashboard($this->mine, 'Мой');
        $foreign = $this->dashboard($this->other, 'Чужой');

        self::assertTrue(Gate::allows('viewAny', BiDashboard::class));
        self::assertTrue(Gate::allows('view', $mine));
        self::assertFalse(Gate::allows('view', $foreign));
        self::assertTrue(Gate::allows('create', BiDashboard::class));
        self::assertTrue(Gate::allows('update', $mine));
        self::assertFalse(Gate::allows('update', $foreign));

        $widget = BiWidget::create([
            'dashboard_id' => $foreign->id,
            'title' => 'Чужой виджет',
            'kind' => BiWidgetKind::Kpi,
            'position' => 0,
        ]);

        self::assertFalse(Gate::allows('delete', $widget));
    }
}
