<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Projects\Services\ProjectService;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\FinAccount;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectMilestone;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\Sidebar;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Этап E5 (Projects): гейтинг прав, CRUD проекта, ресурсы, вехи, связь задач,
 * бюджет из финансов и межтенантная изоляция.
 */
class ProjectsHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Projects Co', 'code' => 'projectsco']);
        $this->adminUser = User::factory()->create(['email' => 'proj-admin@corpms.demo']);
        $this->adminEmployee = $this->makeEmployee($this->company, 'P-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->adminEmployee->refresh();

        $this->project = Project::create([
            'company_id' => $this->company->id,
            'code' => 'PRJ-1',
            'name' => 'Внедрение CRM',
            'status' => 'active',
            'budget' => 100000,
            'start_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'manager_id' => $this->adminEmployee->id,
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function makeEmployee(Company $company, string $no, string $first, string $last, ?string $roleSlug = null): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        if ($roleSlug !== null) {
            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            ]);
        }

        return $employee;
    }

    protected function makeTask(string $title, string $status = 'new', ?string $number = null): Task
    {
        return Task::create([
            'company_id' => $this->company->id,
            'title' => $title,
            'number' => $number ?? 'T-'.uniqid(),
            'status' => $status,
            'type' => 'task',
            'priority' => 'medium',
            'author_id' => $this->adminEmployee->id,
        ]);
    }

    /** Гейтинг: без projects.* — 403; super_admin видит раздел. */
    public function test_permission_gating(): void
    {
        $worker = $this->makeEmployee($this->company, 'W-1', 'Работник', 'Работников', 'employee');
        $workerUser = User::findOrFail($worker->user_id);

        $this->actingAs($workerUser)->get('/projects')->assertForbidden();
        $this->actingAs($workerUser)->get('/projects/'.$this->project->id)->assertForbidden();
        $this->actingAs($workerUser)->post('/projects', ['code' => 'X', 'name' => 'X'])->assertForbidden();

        $this->actingAs($this->adminUser)->get('/projects')->assertOk()->assertSee('Внедрение CRM');
        $this->actingAs($this->adminUser)->get('/projects/'.$this->project->id)->assertOk()->assertSee('Задачи проекта');

        $routes = array_column(Sidebar::build($this->adminUser), 'route');
        $this->assertContains('projects.index', $routes);

        $workerRoutes = array_column(Sidebar::build($workerUser), 'route');
        $this->assertNotContains('projects.index', $workerRoutes);
    }

    /** CRUD проекта, уникальность кода и смена статуса. */
    public function test_project_crud_and_status(): void
    {
        $this->post('/projects', [
            'code' => 'PRJ-2',
            'name' => 'Автоматизация склада',
            'budget' => 250000,
            'start_date' => now()->toDateString(),
            'due_date' => now()->addMonths(2)->toDateString(),
            'manager_id' => $this->adminEmployee->id,
        ])->assertSessionHasNoErrors();

        $project = Project::query()->where('code', 'PRJ-2')->firstOrFail();
        $this->assertSame('planned', $project->status->value);

        // дубль кода
        $this->post('/projects', ['code' => 'PRJ-2', 'name' => 'Дубль'])
            ->assertSessionHasErrors('code');

        // правка
        $this->patch('/projects/'.$project->id, [
            'name' => 'Автоматизация склада и закупок',
            'budget' => 300000,
            'due_date' => now()->addMonths(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Автоматизация склада и закупок', $project->fresh()->name);
        $this->assertSame(300000.0, (float) $project->fresh()->budget);

        // статус
        $this->post('/projects/'.$project->id.'/status', ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $project->fresh()->status->value);

        $this->post('/projects/'.$project->id.'/status', ['status' => 'nonsense'])
            ->assertSessionHasErrors('status');

        $this->get('/projects?status=all')->assertOk()->assertSee('Автоматизация склада и закупок');
    }

    /** Ресурсы проекта: добавление, обновление загрузки, удаление. */
    public function test_members_and_allocation(): void
    {
        $employee = $this->makeEmployee($this->company, 'P-2', 'Иван', 'Петров');

        $this->post('/projects/'.$this->project->id.'/members', [
            'employee_id' => $employee->id,
            'role' => 'member',
            'allocation_percent' => 50,
        ])->assertSessionHasNoErrors();

        $member = ProjectMember::query()->firstOrFail();
        $this->assertSame(50, $member->allocation_percent);

        // повторное добавление обновляет загрузку, а не дублирует
        $this->post('/projects/'.$this->project->id.'/members', [
            'employee_id' => $employee->id,
            'role' => 'manager',
            'allocation_percent' => 80,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ProjectMember::query()->count());
        $member->refresh();
        $this->assertSame(80, $member->allocation_percent);
        $this->assertSame('manager', $member->role->value);

        $this->get('/projects/'.$this->project->id)->assertOk()->assertSee('Петров')->assertSee('80%');

        $this->delete('/projects/'.$this->project->id.'/members/'.$member->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('project_members', 0);

        // чужой сотрудник
        $other = Company::create(['name' => 'Other Proj', 'code' => 'otherproj']);
        $foreignEmployee = Employee::create([
            'company_id' => $other->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'X-1',
            'first_name' => 'Чужой',
            'last_name' => 'Сотрудник',
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        $this->post('/projects/'.$this->project->id.'/members', [
            'employee_id' => $foreignEmployee->id,
            'role' => 'member',
        ])->assertForbidden();
    }

    /** Вехи: добавление, выполнение/возврат, удаление. */
    public function test_milestones(): void
    {
        $this->post('/projects/'.$this->project->id.'/milestones', [
            'title' => 'Прототип',
            'due_date' => now()->addDays(10)->toDateString(),
        ])->assertSessionHasNoErrors();

        $milestone = ProjectMilestone::query()->firstOrFail();
        $this->assertNull($milestone->completed_at);

        $this->post('/projects/'.$this->project->id.'/milestones/'.$milestone->id.'/toggle')->assertSessionHasNoErrors();
        $this->assertNotNull($milestone->fresh()->completed_at);

        $this->post('/projects/'.$this->project->id.'/milestones/'.$milestone->id.'/toggle')->assertSessionHasNoErrors();
        $this->assertNull($milestone->fresh()->completed_at);

        $this->get('/projects/'.$this->project->id)->assertOk()->assertSee('Прототип');

        $this->delete('/projects/'.$this->project->id.'/milestones/'.$milestone->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('project_milestones', 0);
    }

    /** Связь Task ↔ Project и прогресс по завершённым задачам. */
    public function test_task_link_and_progress(): void
    {
        $first = $this->makeTask('Собрать требования');
        $second = $this->makeTask('Настроить интеграции');

        $this->post('/projects/'.$this->project->id.'/tasks', ['task_id' => $first->id])->assertSessionHasNoErrors();
        $this->post('/projects/'.$this->project->id.'/tasks', ['task_id' => $second->id])->assertSessionHasNoErrors();

        $this->assertSame($this->project->id, (int) $first->fresh()->project_id);

        $progress = app(ProjectService::class)->progress($this->project->fresh());
        $this->assertSame(2, $progress['total']);
        $this->assertSame(0, $progress['percent']);

        $first->update(['status' => 'completed', 'completed_at' => now()]);

        $progress = app(ProjectService::class)->progress($this->project->fresh());
        $this->assertSame(1, $progress['completed']);
        $this->assertSame(50, $progress['percent']);

        $this->get('/projects/'.$this->project->id)->assertOk()->assertSee('Собрать требования')->assertSee('50%');

        // отвязка
        $this->delete('/projects/'.$this->project->id.'/tasks/'.$second->id)->assertSessionHasNoErrors();
        $this->assertNull($second->fresh()->project_id);

        // чужая задача
        $other = Company::create(['name' => 'Other Tasks', 'code' => 'othertasks']);
        $foreignTask = Task::create([
            'company_id' => $other->id,
            'title' => 'Чужая задача',
            'number' => 'F-1',
            'status' => 'new',
            'type' => 'task',
            'priority' => 'medium',
        ]);

        $this->post('/projects/'.$this->project->id.'/tasks', ['task_id' => $foreignTask->id])->assertForbidden();
    }

    /** Бюджет проекта: план и факт расходов из финансов. */
    public function test_budget_uses_finance_expenses(): void
    {
        $account = FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Расчётный счёт',
            'kind' => 'bank',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $this->post('/finance/transactions', [
            'account_id' => $account->id,
            'project_id' => $this->project->id,
            'direction' => 'expense',
            'amount' => 25000,
            'occurred_at' => now()->toDateString(),
            'description' => 'Оплата подрядчику',
        ])->assertSessionHasNoErrors();

        $this->post('/finance/transactions', [
            'account_id' => $account->id,
            'project_id' => $this->project->id,
            'direction' => 'income',
            'amount' => 5000,
            'occurred_at' => now()->toDateString(),
            'description' => 'Возврат неиспользованного аванса',
        ])->assertSessionHasNoErrors();

        $budget = app(ProjectService::class)->budget($this->project->fresh());

        $this->assertSame(100000.0, $budget['planned']);
        $this->assertSame(25000.0, $budget['spent']);
        $this->assertSame(5000.0, $budget['received']);
        $this->assertSame(75000.0, $budget['remaining']);
        $this->assertSame(25.0, $budget['percent']);

        $this->get('/projects/'.$this->project->id)->assertOk()->assertSee('25 000');

        // операция с чужим проектом из формы — 403
        $other = Company::create(['name' => 'Other Budget', 'code' => 'otherbudget']);
        $foreignProject = Project::create([
            'company_id' => $other->id,
            'code' => 'F-1',
            'name' => 'Чужой проект',
            'status' => 'active',
            'budget' => 0,
        ]);

        $this->post('/finance/transactions', [
            'account_id' => $account->id,
            'project_id' => $foreignProject->id,
            'direction' => 'expense',
            'amount' => 100,
            'occurred_at' => now()->toDateString(),
        ])->assertForbidden();
    }

    /** Изоляция: чужие проекты, участники и вехи — 403/404, списки только свои. */
    public function test_tenant_isolation(): void
    {
        $other = Company::create(['name' => 'Other Projects', 'code' => 'otherprojects']);
        $foreignProject = Project::create([
            'company_id' => $other->id,
            'code' => 'FOREIGN-1',
            'name' => 'Чужой проект',
            'status' => 'active',
            'budget' => 10,
        ]);
        $foreignMember = ProjectMember::create([
            'company_id' => $other->id,
            'project_id' => $foreignProject->id,
            'employee_id' => $this->makeEmployee($other, 'O-1', 'Чужой', 'Участник')->id,
            'role' => 'member',
            'allocation_percent' => 100,
        ]);
        $foreignMilestone = ProjectMilestone::create([
            'company_id' => $other->id,
            'project_id' => $foreignProject->id,
            'title' => 'Чужая веха',
        ]);

        $this->get('/projects/'.$foreignProject->id)->assertForbidden();
        $this->patch('/projects/'.$foreignProject->id, ['name' => 'Взлом'])->assertForbidden();
        $this->post('/projects/'.$foreignProject->id.'/status', ['status' => 'completed'])->assertForbidden();
        $this->post('/projects/'.$foreignProject->id.'/tasks', ['task_id' => 1])->assertForbidden();

        // вложенный объект чужого проекта в своём маршруте — 404
        $this->post('/projects/'.$this->project->id.'/milestones/'.$foreignMilestone->id.'/toggle')->assertNotFound();
        $this->delete('/projects/'.$this->project->id.'/members/'.$foreignMember->id)->assertNotFound();

        $this->get('/projects?status=all')->assertOk()->assertDontSee('Чужой проект');

        $this->assertSame('active', $this->project->fresh()->status->value);
    }
}
