<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-контур CRUD панели (п.5–11 ТЗ) + RBAC scope через middleware.
 */
class OrgStructureHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $adminUser;
    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Test Co', 'code' => 'testco']);

        // super_admin обходит permission, но RBAC привязан к сотруднику — создаём employee для админа
        $this->adminUser = User::factory()->create([
            'email' => 'admin@corpms.demo',
            'password' => 'password',
        ]);
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Админов', userId: $this->adminUser->id);
        $superAdminRoleId = Role::query()->where('slug', 'super_admin')->value('id');
        EmployeeRole::create(['employee_id' => $this->adminEmployee->id, 'role_id' => $superAdminRoleId]);
        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?string $chatId = null, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    public function test_departments_page_renders_and_store_creates_department(): void
    {
        // нужен employee текущего пользователя для company_id — уже есть в setUp

        $this->get('/departments')->assertOk()->assertSee('Отделы');

        $this->post('/departments', [
            'name' => 'Продажи',
            'code' => 'SALES',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('departments', ['code' => 'SALES', 'name' => 'Продажи']);
    }

    public function test_employees_index_show_update_and_roles(): void
    {
        $employee = $this->employee('E-1', 'Иван', 'Иванов');
        $position = Position::create([
            'company_id' => $this->company->id,
            'name' => 'Инженер',
            'code' => 'ENG',
        ]);
        $employee->update(['position_id' => $position->id]);

        $this->get('/employees?q=Ива')->assertOk()->assertSee('Иванов');

        $this->get('/employees/' . $employee->id)->assertOk()->assertSee('Профиль');

        $this->followingRedirects()
            ->patch('/employees/' . $employee->id, [
                'first_name' => 'Иван',
                'last_name' => 'Иванов',
                'employee_no' => 'E-1',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertSee('inactive');

        $role = Role::query()->where('slug', 'employee')->first();

        $this->put('/employees/' . $employee->id . '/roles', ['role_ids' => [$role->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('employee_roles', ['employee_id' => $employee->id, 'role_id' => $role->id]);
    }

    public function test_anonymous_redirects_to_login(): void
    {
        auth()->logout();

        $this->get('/employees')->assertRedirect('/login');
    }

    public function test_company_list(): void
    {
        $this->get('/companies')->assertOk()->assertSee('Test Co');
    }
}
