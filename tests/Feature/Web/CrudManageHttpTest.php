<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Tasks\Services\TaskService;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Holiday;
use App\Models\Position;
use App\Models\Role;
use App\Models\ScheduleAssignment;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Сквозной CRUD-контур: правка/удаление реквизитов всех сущностей
 * + создание сотрудника через UI + смена своего пароля.
 */
class CrudManageHttpTest extends TestCase
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

    /** Админ видит кнопки управления; замдиректора (companies.view без companies.manage) — нет (Blade-гейт checkBoxPermission). */
    public function test_manage_controls_gated_by_permission(): void
    {
        $this->get('/companies')
            ->assertOk()
            ->assertSee('Добавить компанию')
            ->assertSee('name="_method"', false);

        $deputy = $this->employee('P-1', 'Заместитель', 'Директора');
        $deputyRole = Role::query()->where('slug', 'deputy_director')->firstOrFail();
        EmployeeRole::create(['employee_id' => $deputy->id, 'role_id' => $deputyRole->id]);

        $this->actingAs(User::findOrFail($deputy->user_id))
            ->get('/companies')
            ->assertOk()
            ->assertDontSee('Добавить компанию')
            ->assertDontSee('name="_method"', false);
    }

    /* ---------------- праздники ---------------- */

    public function test_holiday_create_persists_kind_and_is_day_off_then_deleted(): void
    {
        $response = $this->post('/holidays', [
            'name' => 'День народного единства',
            'date' => '2026-11-04',
            'kind' => 'public',
        ]);
        $errors = $response->getSession()?->get('errors');
        $this->assertTrue($errors === null || $errors->isEmpty(), 'errors: '.print_r($errors?->all(), true).' status='.$response->getStatusCode());
        $cnt = Holiday::count();
        $this->assertSame(1, $cnt, 'holiday rows='.$cnt.' session='.\json_encode($response->getSession()->all(), \JSON_UNESCAPED_UNICODE));

        $holiday = Holiday::query()->where('name', 'День народного единства')->firstOrFail();
        $this->assertSame('public', $holiday->kind->value);
        $this->assertTrue($holiday->is_day_off);

        // рабочий «праздник» — не выходной
        $this->post('/holidays', [
            'name' => 'Корпоративный день',
            'date' => '2026-11-20',
            'kind' => 'operating',
        ])->assertRedirect();

        $this->assertFalse(Holiday::query()->where('name', 'Корпоративный день')->firstOrFail()->is_day_off);

        $this->delete('/holidays/'.$holiday->id)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);
    }

    /* ---------------- графики ---------------- */

    public function test_schedule_update_and_delete_guards(): void
    {
        $schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => '8-17',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);

        $this->patch('/schedules/'.$schedule->id, [
            'name' => '08:00-17:00',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 15,
            'min_rest_hours' => 12,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('work_schedules', ['id' => $schedule->id, 'name' => '08:00-17:00', 'min_rest_hours' => 12]);

        // график со сотрудниками удалять нельзя
        $employee = $this->employee('E-9', 'Иван', 'Иванов');
        ScheduleAssignment::create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-11-01',
        ]);

        $this->delete('/schedules/'.$schedule->id)->assertForbidden();

        // после снятия назначения — разрешено
        ScheduleAssignment::query()->firstOrFail()->deleteOrFail();

        $this->delete('/schedules/'.$schedule->id)->assertRedirect();
        $this->assertDatabaseMissing('work_schedules', ['id' => $schedule->id]);
    }

    /* ---------------- компании и должности ---------------- */

    public function test_company_update_and_delete_guard(): void
    {
        $this->patch('/companies/'.$this->company->id, [
            'name' => 'Test Co',
            'code' => 'testco',
            'inn' => '7801123456',
            'region' => 'Санкт-Петербург',
            'timezone' => 'Europe/Moscow',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'inn' => '7801123456']);

        // занятая компания не удаляется
        $this->delete('/companies/'.$this->company->id)->assertForbidden();

        // Справочник компаний — глобальная функция платформенной роли:
        // пустая компания (в т.ч. чужого тенанта) удаляется super_admin'ом.
        // Занятая компания (в т.ч. своя) не удаляется — бизнес-правило ниже.
        $empty = Company::create(['name' => 'Пустая', 'code' => 'emptyco']);
        $this->delete('/companies/'.$empty->id)->assertRedirect();
        $this->assertDatabaseMissing('companies', ['id' => $empty->id]);

        // роль без companies.manage (замдиректора) справочник не правит
        $deputy = $this->employee('P-9', 'Заместитель', 'Директора');
        EmployeeRole::create([
            'employee_id' => $deputy->id,
            'role_id' => Role::query()->where('slug', 'deputy_director')->value('id'),
        ]);
        $foreign = Company::create(['name' => 'Чужая', 'code' => 'foreignco']);
        $this->actingAs(User::findOrFail($deputy->user_id))
            ->patch('/companies/'.$foreign->id, ['name' => 'Взлом', 'code' => 'foreignco'])
            ->assertForbidden();
        $this->assertDatabaseHas('companies', ['id' => $foreign->id, 'name' => 'Чужая']);
    }

    public function test_position_update_and_delete_guard(): void
    {
        $position = Position::create(['company_id' => $this->company->id, 'name' => 'Инженер', 'code' => 'ENG', 'grade' => 'S1']);

        $this->patch('/positions/'.$position->id, [
            'name' => 'Ведущий инженер',
            'code' => 'ENG',
            'grade' => 'S2',
            'description' => 'Разработка встроенных сервисов',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('positions', ['id' => $position->id, 'grade' => 'S2']);

        // закреплённая за сотрудником должность не удаляется
        $employee = $this->employee('E-2', 'Пётр', 'Петров');
        $employee->update(['position_id' => $position->id]);

        $this->delete('/positions/'.$position->id)->assertForbidden();

        $employee->delete();
        $this->delete('/positions/'.$position->id)->assertRedirect();
        $this->assertDatabaseMissing('positions', ['id' => $position->id]);
    }

    /* ---------------- отделы ---------------- */

    public function test_department_delete_guards(): void
    {
        $parent = Department::create(['company_id' => $this->company->id, 'name' => 'Родитель', 'code' => 'PARENT']);
        $child = Department::create(['company_id' => $this->company->id, 'name' => 'Ребёнок', 'code' => 'CHILD', 'parent_id' => $parent->id]);

        // есть дочерний отдел
        $this->delete('/departments/'.$parent->id)->assertForbidden();

        // есть сотрудник
        $employee = $this->employee('E-3', 'Алекс', 'Алексеев');
        $employee->update(['department_id' => $child->id]);

        $this->delete('/departments/'.$child->id)->assertForbidden();

        // пустой отдел удаляется
        $employee->update(['department_id' => null]);
        $this->delete('/departments/'.$child->id)->assertRedirect();
        $this->assertDatabaseMissing('departments', ['id' => $child->id]);
    }

    /* ---------------- сотрудники ---------------- */

    public function test_employee_store_creates_card(): void
    {
        $department = Department::create(['company_id' => $this->company->id, 'name' => 'IT', 'code' => 'IT']);
        $position = Position::create(['company_id' => $this->company->id, 'name' => 'Инженер', 'code' => 'ENG']);

        $this->post('/employees', [
            'employee_no' => 'NEW-77',
            'last_name' => 'Смирнов',
            'first_name' => 'Олег',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
            'hired_at' => '2026-11-06',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'NEW-77',
            'last_name' => 'Смирнов',
            'first_name' => 'Олег',
            'company_id' => $this->company->id,
        ]);
    }

    /* ---------------- задачи ---------------- */

    public function test_task_update_and_delete_by_author(): void
    {
        $task = $this->createTask($this->adminEmployee);

        $this->patch('/tasks/'.$task->id, [
            'title' => 'Скорректированный заголовок',
            'priority' => 'high',
            'deadline' => '2026-12-01T12:00',
        ])->assertRedirect()->assertSessionHas('status');

        $task->refresh();
        $this->assertSame('Скорректированный заголовок', $task->title);
        $this->assertSame('high', $task->priority->value);

        $this->delete('/tasks/'.$task->id)->assertRedirect();
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_task_delete_blocked_with_open_subtasks(): void
    {
        $task = $this->createTask($this->adminEmployee);

        $subtask = $this->createSubtask($this->adminEmployee, $task, status: 'in_progress');

        $this->delete('/tasks/'.$task->id)->assertSessionHasErrors();

        $subtask->update(['status' => 'cancelled']);

        $this->delete('/tasks/'.$task->id)->assertRedirect();
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    /* ---------------- профиль ---------------- */

    public function test_change_password_requires_current_and_updates(): void
    {
        $this->put('/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertSessionHasErrors(['current_password']);

        $this->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertRedirect()->assertSessionHas('status');

        $this->adminUser->refresh();
        $this->assertTrue(
            Hash::check('new-secret-password', $this->adminUser->password)
        );
    }

    /* ---------------- файлы задачи ---------------- */

    public function test_task_attachment_upload_download_delete(): void
    {
        $task = $this->createTask($this->adminEmployee);
        $disk = Storage::disk('local');

        $this->post('/tasks/'.$task->id.'/attachments', [
            'file' => UploadedFile::fake()->create('spec.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('status');

        $attachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();
        $this->assertSame('spec.pdf', $attachment->original_name);
        $this->assertTrue($disk->exists($attachment->file_path));

        // скачивание: автором задачи
        $response = $this->get('/tasks/'.$task->id.'/attachments/'.$attachment->id);
        $response->assertOk()->assertDownload('spec.pdf');

        // посторонний сотрудник не имеет доступа к файлу чужой задачи
        $outsider = $this->employee('P-2', 'Посторонний', 'Чужой');
        EmployeeRole::create(['employee_id' => $outsider->id, 'role_id' => Role::query()->where('slug', 'employee')->value('id')]);
        $this->actingAs(User::findOrFail($outsider->user_id))
            ->get('/tasks/'.$task->id.'/attachments/'.$attachment->id)
            ->assertForbidden();

        // удаление: загрузивший (админ в роли автора)
        $this->actingAs($this->adminUser)
            ->delete('/tasks/'.$task->id.'/attachments/'.$attachment->id)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('task_attachments', ['id' => $attachment->id]);
        $this->assertFalse($disk->exists($attachment->file_path));
    }

    public function test_task_attachment_upload_forbidden_for_related_nobody(): void
    {
        // автор задачи — сотрудник с ролью employee (scope own): сам может приложить
        $plain = $this->employee('P-3', 'Автор', 'Простой');
        EmployeeRole::create(['employee_id' => $plain->id, 'role_id' => Role::query()->where('slug', 'employee')->value('id')]);
        $task = $this->createTask($plain);
        $user = User::findOrFail($plain->user_id);

        $this->actingAs($user)->post('/tasks/'.$task->id.'/attachments', [
            'file' => UploadedFile::fake()->create('check.txt', 10, 'text/plain'),
        ])->assertRedirect();

        /** @var TaskAttachment $attachment */
        $attachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();

        // посторонний с ролью employee не видит файл чужой задачи (scope own → 403 в контроллере)
        $outsider = $this->employee('P-4', 'Посторонний', 'Совсем');
        EmployeeRole::create(['employee_id' => $outsider->id, 'role_id' => Role::query()->where('slug', 'employee')->value('id')]);
        $outsiderUser = User::findOrFail($outsider->user_id);

        $this->actingAs($outsiderUser)
            ->get('/tasks/'.$task->id.'/attachments/'.$attachment->id)
            ->assertForbidden();

        // и не может удалить: не прикладывавший, не автор, не tasks.manage
        $this->actingAs($outsiderUser)
            ->delete('/tasks/'.$task->id.'/attachments/'.$attachment->id)
            ->assertForbidden();

        // при этом сам автор файл скачивает
        $this->actingAs($user)
            ->get('/tasks/'.$task->id.'/attachments/'.$attachment->id)
            ->assertOk();
    }

    /* ---------------- пагинация ---------------- */

    public function test_employee_list_paginates(): void
    {
        // 21 сотрудник с фамилиями «Яковлев-01..21» — все за админом («Админов») в алфавитном порядке
        for ($i = 1; $i <= 21; $i++) {
            $this->employee(sprintf('EPL-%02d', $i), 'Иван', 'Яковлев-'.sprintf('%02d', $i));
        }

        $this->get('/employees')
            ->assertOk()
            ->assertSee('Страница 1 из 2', false)
            ->assertSee('page=2', false)
            ->assertDontSee('Яковлев-21'); // 21-й — на второй странице

        $this->get('/employees?page=2')
            ->assertOk()
            ->assertSee('Яковлев-21');

        // фильтры не теряются при переключении страниц (q в URL-энкоде)
        $this->get('/employees?q=Яковлев')
            ->assertOk()
            ->assertSee('page=2', false)
            ->assertSee('q=%D0%AF', false);
    }

    public function test_mobile_navbar_has_bell_and_theme_without_dead_toggler(): void
    {
        // на телефоне вторая шапка не должна показывать мёртвый бургер,
        // а тема/уведомления обязаны быть доступны без d-none
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('id="crm-theme-toggle"', false)
            ->assertSee('data-bs-target="#crm-bell"', false)
            // мёртвый бургер верхней шапки (открывающий пустой collapse) удалён
            ->assertDontSee('data-bs-target="#navbar-menu"', false)
            ->assertDontSee('id="navbar-menu"', false);
    }

    public function test_permission_checks_do_not_nplus1_in_menu(): void
    {
        // сайдбар проверяет ~35 permissions на каждый рендер; без кэша в сервисе
        // это давало 70+ SQL-запросов на страницу (Regression-защита)
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get('/search?q=x')->assertOk();

        // бейзлайн: сессия (2-3), аутентификация (1), содержимое страницы и меню.
        // без мемоизации было бы 70-100+
        $this->assertLessThanOrEqual(16, $queries);
    }

    /* --------------- helpers --------------- */

    protected function createTask(Employee $author, string $title = 'Заголовок'): Task
    {
        return $this->app->make(TaskService::class)->create($author, [
            'title' => $title,
            'type' => 'task',
            'priority' => 'medium',
            'department_id' => null,
            'assignees' => [['employee_id' => $author->id]],
        ]);
    }

    protected function createSubtask(Employee $author, Task $parent, string $status = 'new'): Task
    {
        $subtask = $this->app->make(TaskService::class)->createSubtask($author, $parent, [
            'title' => 'Подзадача',
            'priority' => 'medium',
        ]);

        $subtask->update(['status' => $status]);

        return $subtask;
    }
}
