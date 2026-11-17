<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PermissionService();
        $this->seed(RbacSeeder::class);

        // источники структуры для FK
        Company::create(['name' => 'TEST Co', 'code' => 'testco']);
    }

    protected function employee(User $user, string ...$roleSlugs): Employee
    {
        $employee = Employee::create([
            'company_id' => 1,
            'user_id' => $user->id,
            'employee_no' => 'T-' . uniqid(),
            'first_name' => 'Тест',
            'last_name' => 'Тестов',
            'status' => 'active',
        ]);

        if ($roleSlugs !== []) {
            $employee->roles()->attach(Role::query()->whereIn('slug', $roleSlugs)->pluck('id'));
        }

        return $employee;
    }

    public function test_roles_and_permissions_seeded(): void
    {
        $this->assertDatabaseCount('roles', 7);
        $this->assertDatabaseCount('permissions', count(\App\Shared\Permissions\PermissionMatrix::all()));
        $this->assertDatabaseCount('role_permissions', count(\App\Models\RolePermission::all()));
    }

    public function test_super_admin_has_everything(): void
    {
        $user = User::factory()->create();
        $this->employee($user, 'super_admin');

        $this->assertSame(PermissionScope::Company, $this->service->can($user, 'tasks.view'));
        $this->assertSame(PermissionScope::Company, $this->service->can($user, 'audit.view'));
    }

    public function test_department_director_scope_department(): void
    {
        $user = User::factory()->create();
        $this->employee($user, 'department_director');

        $this->assertSame(PermissionScope::Department, $this->service->can($user, 'tasks.view'));
        $this->assertTrue($this->service->checkScope($user, 'tasks.view', PermissionScope::Department));
        $this->assertFalse($this->service->checkScope($user, 'tasks.view', PermissionScope::Company));
    }

    public function test_plain_employee_scope_own_and_no_foreign_permissions(): void
    {
        $user = User::factory()->create();
        $this->employee($user, 'employee');

        $this->assertSame(PermissionScope::Own, $this->service->can($user, 'tasks.view'));
        $this->assertFalse($this->service->check($user, 'tasks.assign'));
        $this->assertFalse($this->service->check($user, 'employees.manage'));
    }

    public function test_user_without_employee_denied(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->service->check($user, 'tasks.view'));
    }

    public function test_override_grants_access(): void
    {
        $user = User::factory()->create();
        $employee = $this->employee($user, 'employee');

        $perm = \App\Models\Permission::query()->where('slug', 'employees.view')->first();
        \App\Models\EmployeePermissionOverride::create([
            'employee_id' => $employee->id,
            'permission_id' => $perm->id,
            'scope' => PermissionScope::Department->value,
        ]);

        $this->assertSame(PermissionScope::Department, $this->service->can($user, 'employees.view'));
        $this->assertTrue($this->service->check($user, 'employees.view'));
    }

    public function test_company_director_scope_company(): void
    {
        $user = User::factory()->create();
        $this->employee($user, 'company_director');

        $this->assertSame(PermissionScope::Company, $this->service->can($user, 'tasks.view'));
        $this->assertTrue($this->service->checkScope($user, 'tasks.view', PermissionScope::Company));
        $this->assertTrue($this->service->check($user, 'settings.view'));
    }
}
