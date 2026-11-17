<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A7: учёт scope в EnsurePermission — суффикс `permission:slug@scope`
 * (own < department < company) в маршрутах; без суффикса — прежнее поведение.
 */
class PermissionScopeGateTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $superUser;

    protected User $hrUser;

    protected Employee $hrEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Scope Co', 'code' => 'scopeco']);

        [$this->superUser] = $this->userWithRole('S-1', 'Супер', 'Админ', 'super_admin');
        [$this->hrUser, $this->hrEmployee] = $this->userWithRole('H-1', 'Галина', 'HR', 'hr_manager');

        // маршрут с заведомо неверным scope: должен падать fail-closed (500), а не пускать
        Route::get('/__a7_bad_scope', fn () => response('ok'))
            ->middleware('permission:settings.view@badscope');
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    protected function userWithRole(string $no, string $first, string $last, string $roleSlug): array
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);

        return [$user, $employee];
    }

    protected function grant(Employee $employee, string $permissionSlug, string $scope): void
    {
        EmployeePermissionOverride::create([
            'employee_id' => $employee->id,
            'permission_id' => Permission::query()->where('slug', $permissionSlug)->value('id'),
            'scope' => $scope,
        ]);
    }

    /** POST /settings — settings.manage@company: department-scope проходит раньше, теперь 403. */
    public function test_company_scope_route_rejects_department_grant(): void
    {
        $this->grant($this->hrEmployee, 'settings.manage', 'department');

        $this->actingAs($this->hrUser)
            ->post('/settings', ['corpms.app.name' => 'Scope Co'])
            ->assertForbidden();
    }

    /** Тот же маршрут: own-scope (самый узкий) тоже отклоняется. */
    public function test_company_scope_route_rejects_own_grant(): void
    {
        $this->grant($this->hrEmployee, 'settings.manage', 'own');

        $this->actingAs($this->hrUser)
            ->post('/settings', ['corpms.app.name' => 'Scope Co'])
            ->assertForbidden();
    }

    /** Override может расширять до company — тогда маршрут проходит. */
    public function test_company_scope_route_accepts_company_grant(): void
    {
        $this->grant($this->hrEmployee, 'settings.manage', 'company');

        $response = $this->actingAs($this->hrUser)
            ->post('/settings', ['corpms.app.name' => 'Scope Co']);

        $this->assertFalse($response->isForbidden(), 'Company-scope обязан проходить gate');
    }

    /** super_admin всегда company-scope — GET /settings проходит. */
    public function test_super_admin_passes_scope_gate(): void
    {
        $this->actingAs($this->superUser)->get('/settings')->assertOk();
    }

    /** Обратная совместимость: маршрут без `@scope` пускает любой scope (own в т.ч.). */
    public function test_route_without_suffix_keeps_any_scope_behavior(): void
    {
        // роль employee даёт attendance.view=own — GET /attendance без суффикса не должен рвать 403
        [$workerUser] = $this->userWithRole('W-1', 'Вася', 'Рядовой', 'employee');

        $this->actingAs($workerUser)->get('/attendance')->assertOk();
    }

    /** Некорректный scope в маршруте — fail-closed (500), а не тихий пропуск. */
    public function test_invalid_scope_suffix_fails_closed(): void
    {
        $this->actingAs($this->superUser)->get('/__a7_bad_scope')->assertServerError();
    }

    /** Реальный маршрут payroll.manage@company: узкий override не проходит. */
    public function test_payroll_manage_route_rejects_narrow_grant(): void
    {
        [, $directorEmployee] = $this->userWithRole('D-1', 'Дмитрий', 'Директор', 'department_director');
        $directorUser = $directorEmployee->user;

        $this->grant($directorEmployee, 'payroll.manage', 'department');

        $this->actingAs($directorUser)
            ->post('/payroll', ['name' => 'Ноябрь 2026'])
            ->assertForbidden();
    }
}
