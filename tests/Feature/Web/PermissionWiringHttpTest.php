<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Biometric\Services\BiometricService;
use App\Models\BiometricProfile;
use App\Models\Company;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentWorkflow;
use App\Models\DocumentWorkflowStep;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Закрытие «осиротевших» прав матрицы: листья право гейтится маршрутом,
 * biometry.profiles.manage — маршрутом disable/delete; tasks.complete,
 * documents.reject, structure.manage, tasks.assign_department — inline
 * в контроллерах; employees.view_props / signatures.view / biometry.dashboard.view —
 * гейты видимости на страницах.
 */
class PermissionWiringHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected WorkSchedule $schedule;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Wiring Co', 'code' => 'wireco']);
        $this->schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_start' => '13:00:00',
            'break_end' => '14:00:00',
            'grace_minutes' => 10,
        ]);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Администратор', 'RF', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Марина', 'Работница', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);

        // workflow: один шаг — автор (нужен submit/approve/reject документов)
        DocumentWorkflow::create([
            'company_id' => $this->company->id,
            'name' => 'Стандарт',
            'is_active' => true,
        ]);
        DocumentWorkflowStep::create([
            'workflow_id' => DocumentWorkflow::max('id'),
            'step_order' => 1,
            'name' => 'Автор',
            'actor_type' => 'author',
            'required_action' => 'approve',
            'is_terminal' => true,
        ]);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'work_schedule_id' => $this->schedule->id,
        ]);
    }

    /** Кастомная роль с набором прав (scope company) — для deny/allow кейсов. */
    protected function roleWithPerms(string $slug, array $perms): Role
    {
        $role = Role::create([
            'slug' => $slug,
            'name' => $slug,
            'is_system' => false,
            'description' => $slug,
        ]);

        foreach ($perms as $perm) {
            RolePermission::create([
                'role_id' => $role->id,
                'permission_id' => Permission::query()->where('slug', $perm)->value('id'),
                'scope' => 'company',
            ]);
        }

        return $role;
    }

    /** Пользователь + карточка сотрудника с заданной ролью. */
    protected function holder(Role $role, string $no, string $first, string $last): array
    {
        $user = User::factory()->create();
        $employee = $this->employee($no, $first, $last, $user->id);
        EmployeeRole::create(['employee_id' => $employee->id, 'role_id' => $role->id]);

        return [$user, $employee];
    }

    /* -------------------- leaves.request -------------------- */

    public function test_leaves_create_gated_by_leaves_request(): void
    {
        // пользователь без роли и карточки — 403 на middleware
        $bareUser = User::factory()->create();
        $this->actingAs($bareUser);

        $this->post('/leaves', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-05', 'type' => 'vacation',
        ])->assertForbidden();
        self::assertSame(0, LeaveRequest::query()->count());

        // сотрудник с leaves.request=own — заявка на себя
        $this->actingAs($this->workerUser);
        $this->post('/leaves', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-10',
            'type' => 'vacation', 'reason' => 'Ежегодный отпуск',
        ])->assertRedirect()->assertSessionHas('status');

        $leave = LeaveRequest::query()->sole();
        self::assertSame($this->worker->id, $leave->employee_id);
        self::assertSame('pending', $leave->status->value);
    }

    /* -------------------- biometry.profiles.manage -------------------- */

    public function test_biometric_profile_disable_gated_by_profiles_manage(): void
    {
        app(BiometricService::class)->enrollProfile($this->worker, 'generic', null, hash('sha256', 'tpl'));
        $profile = BiometricProfile::query()->where('employee_id', $this->worker->id)->sole();

        // сотрудник без biometry.profiles.manage — 403
        $this->actingAs($this->workerUser);
        $this->post('/biometrics/profiles/'.$profile->id.'/disable')->assertForbidden();
        self::assertSame('enrolled', $profile->fresh()->status->value);

        // новый грант hr_manager: biometry.profiles.manage=company — отключает профиль
        $hrRole = Role::query()->where('slug', 'hr_manager')->firstOrFail();
        [$hrUser] = $this->holder($hrRole, 'HR-1', 'Ирина', 'Кадровая');
        $this->actingAs($hrUser);

        $this->post('/biometrics/profiles/'.$profile->id.'/disable')->assertRedirect();
        $this->assertDatabaseHas('biometric_profiles', ['id' => $profile->id, 'status' => 'disabled']);
    }

    /* -------------------- tasks.complete (inline) -------------------- */

    public function test_task_completion_allowed_for_author_and_denied_for_assignee(): void
    {
        $this->post('/tasks', [
            'title' => 'Задача на завершение',
            'type' => 'task',
            'priority' => 'medium',
            'assignee_type' => 'employee',
            'employee_id' => $this->worker->id,
        ])->assertRedirect();

        $task = Task::query()->where('title', 'Задача на завершение')->sole();

        // исполнитель переводит в работу
        $this->actingAs($this->workerUser);
        $this->put('/tasks/'.$task->id.'/status', ['status' => 'in_progress'])
            ->assertRedirect()
            ->assertSessionHas('status');

        // исполнитель-не-автор завершить не может (нет tasks.complete/manage)
        $this->put('/tasks/'.$task->id.'/status', ['status' => 'completed'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        self::assertSame('in_progress', $task->fresh()->status->value);

        // автор завершает
        $this->actingAs($this->adminUser);
        $this->put('/tasks/'.$task->id.'/status', ['status' => 'completed'])
            ->assertRedirect()
            ->assertSessionHas('status');
        self::assertSame('completed', $task->fresh()->status->value);
    }

    /* -------------------- tasks.assign_department (inline) -------------------- */

    public function test_department_assignment_gated(): void
    {
        $dept = Department::create(['company_id' => $this->company->id, 'name' => 'Продажи', 'code' => 'SALES']);
        $this->worker->update(['department_id' => $dept->id]);

        // роль с tasks.create, но без tasks.assign_department → отказ при назначении отделу
        $role = $this->roleWithPerms('task-maker', ['tasks.view', 'tasks.create']);
        [$user] = $this->holder($role, 'TM-1', 'Тарас', 'Постановщик');
        $this->actingAs($user);

        $this->post('/tasks', [
            'title' => 'Отдел-задача',
            'type' => 'task',
            'priority' => 'low',
            'assignee_type' => 'department',
            'department_id' => $dept->id,
        ])->assertStatus(500);

        self::assertSame(0, Task::query()->where('title', 'Отдел-задача')->count());

        // директор отдела (tasks.assign_department=department) — назначение проходит
        $dirRole = Role::query()->where('slug', 'department_director')->firstOrFail();
        [$dirUser, $dirEmployee] = $this->holder($dirRole, 'DIR-1', 'Директор', 'Отдела');
        $dirEmployee->update(['department_id' => $dept->id]);
        $this->actingAs($dirUser);

        $this->post('/tasks', [
            'title' => 'Отдел-задача',
            'type' => 'task',
            'priority' => 'low',
            'assignee_type' => 'department',
            'department_id' => $dept->id,
        ])->assertRedirect();
    }

    /* -------------------- documents.reject (inline) -------------------- */

    public function test_document_rejection_requires_documents_reject(): void
    {
        $category = DocumentCategory::create(['code' => 'ORD', 'name' => 'Приказы']);
        $this->post('/documents', [
            'title' => 'Приказ №5',
            'category_id' => $category->id,
            'body' => 'Тело приказа',
        ])->assertRedirect();

        $document = Document::query()->where('title', 'Приказ №5')->sole();
        $this->post('/documents/'.$document->id.'/submit')->assertRedirect();
        $this->assertSame('submitted', $document->fresh()->status->value);

        // роль с documents.approve, но без documents.reject — отказ в согласовании 403
        $role = $this->roleWithPerms('approver-only', ['documents.view', 'documents.approve']);
        [$user] = $this->holder($role, 'AP-1', 'Аркадий', 'Согласовант');

        $this->actingAs($user);
        $this->post('/documents/'.$document->id.'/decide', [
            'decision' => 'rejected', 'comment' => 'нет план',
        ])->assertForbidden();
        self::assertSame('submitted', $document->fresh()->status->value);

        // у супер-админа documents.reject есть — отказ проходит
        $this->actingAs($this->adminUser);
        $this->post('/documents/'.$document->id.'/decide', [
            'decision' => 'rejected', 'comment' => 'Переделать',
        ])->assertRedirect()->assertSessionHas('status');
        self::assertSame('revision', $document->fresh()->status->value);
    }

    /* -------------------- structure.manage (inline) -------------------- */

    public function test_department_move_requires_structure_manage(): void
    {
        $dept = Department::create(['company_id' => $this->company->id, 'name' => 'IT', 'code' => 'IT']);
        $parent = Department::create(['company_id' => $this->company->id, 'name' => 'HQ', 'code' => 'HQ']);

        // manage реквизитов есть, structure.manage — нет
        $role = $this->roleWithPerms('dept-admin', ['departments.view', 'departments.manage']);
        [$user] = $this->holder($role, 'D-1', 'Денис', 'Завотделом');
        $this->actingAs($user);

        // переименование — реквизиты, работаем без structure.manage
        $this->patch('/departments/'.$dept->id, ['name' => 'IT-отдел'])
            ->assertRedirect()
            ->assertSessionHas('status');

        // перенос под родителя — 403
        $this->patch('/departments/'.$dept->id, ['name' => 'IT-отдел', 'parent_id' => $parent->id])
            ->assertForbidden();

        // супер-админ переносит
        $this->actingAs($this->adminUser);
        $this->patch('/departments/'.$dept->id, ['name' => 'IT-отдел', 'parent_id' => $parent->id])
            ->assertRedirect()
            ->assertSessionHas('status');
        self::assertSame($parent->id, $dept->fresh()->parent_id);
    }

    /* -------------------- employees.view_props -------------------- */

    public function test_employee_props_hidden_without_view_props(): void
    {
        $this->adminEmployee->update([
            'email' => 'boss.root@corpms.local',
            'phone' => '+79162000111',
        ]);

        // employees.view без employees.view_props — реквизиты скрыты
        $role = $this->roleWithPerms('staff-view', ['employees.view']);
        [$user] = $this->holder($role, 'SV-1', 'Степан', 'Обозреватель');
        $this->actingAs($user);

        $this->get('/employees/'.$this->adminEmployee->id)
            ->assertOk()
            ->assertSee('Реквизиты скрыты')
            ->assertDontSee('boss.root@corpms.local')
            ->assertDontSee('+79162000111');

        // держащий view_props — видит
        $this->actingAs($this->adminUser);
        $this->get('/employees/'.$this->adminEmployee->id)
            ->assertOk()
            ->assertSee('boss.root@corpms.local');
    }

    /* -------------------- signatures.view -------------------- */

    public function test_document_signatures_block_gated_by_signatures_view(): void
    {
        $category = DocumentCategory::create(['code' => 'SG', 'name' => 'Договорные']);
        $this->post('/documents', [
            'title' => 'Договор на поставку',
            'category_id' => $category->id,
            'body' => 'Текст договора',
        ])->assertRedirect();
        $document = Document::query()->where('title', 'Договор на поставку')->sole();

        $role = $this->roleWithPerms('doc-viewer', ['documents.view']);
        [$user, $viewer] = $this->holder($role, 'DV-1', 'Вера', 'Смотрящая');
        $this->actingAs($user);

        // documents.view есть, signatures.view нет → блок подписей скрыт
        $this->get('/documents/'.$document->id)
            ->assertOk()
            ->assertDontSee('Подписи');

        // супер-админ видит блок
        $this->actingAs($this->adminUser);
        $this->get('/documents/'.$document->id)->assertOk()->assertSee('Подписи');

        // запрос подписи → сам подписант видит блок («свой» путь)
        $this->post('/documents/'.$document->id.'/submit')->assertRedirect();
        $this->post('/documents/'.$document->id.'/decide', ['decision' => 'approved'])
            ->assertRedirect()->assertSessionHas('status');
        $this->post('/documents/'.$document->id.'/register')->assertRedirect();
        $this->post('/documents/'.$document->id.'/signatures/request', [
            'signer_ids' => [$viewer->id],
        ])->assertRedirect();

        $this->actingAs($user);
        $this->get('/documents/'.$document->id)->assertOk()->assertSee('Подписи');
    }

    /* -------------------- biometry.dashboard.view -------------------- */

    public function test_dashboard_biometrics_card_gated(): void
    {
        // супер-админ видит сводку биометрии
        $this->get('/dashboard')->assertOk()->assertSee('Биометрия и устройства');

        // сотрудник без biometry.dashboard.view — карточки нет
        $this->actingAs($this->workerUser);
        $this->get('/dashboard')->assertOk()->assertDontSee('Биометрия и устройства');
    }
}
