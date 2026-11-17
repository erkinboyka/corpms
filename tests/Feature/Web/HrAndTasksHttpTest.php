<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-контур: графики/отпуска/посещаемость/задачи/уведомления (п.16–49 ТЗ).
 */
class HrAndTasksHttpTest extends TestCase
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
        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Админов', userId: $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->actingAs($this->adminUser);

        WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => '8-17',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
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
        ]);
    }

    /* ---------------- отпуска ---------------- */

    public function test_employees_status_filter_includes_dismissed(): void
    {
        $dismissed = $this->employee('F-1', 'Воронова', 'Лидия');
        $dismissed->update(['status' => 'dismissed']);
        $this->employee('A-2', 'Синицына', 'Ольга');

        // UX-D3/S8: значение фильтра обязано совпадать со значением enum ('dismissed')
        $this->get('/employees?status=dismissed')
            ->assertOk()
            ->assertSee('Воронова')
            ->assertDontSee('Синицына')
            ->assertDontSee('Админов');

        $this->get('/employees')
            ->assertOk()
            ->assertSee('Синицына')
            ->assertDontSee('Воронова');
    }

    public function test_leave_request_and_decision_flow(): void
    {
        $manager = $this->employee('M-1', 'Ольга', 'Management');
        EmployeeRole::create([
            'employee_id' => $manager->id,
            'role_id' => Role::query()->where('slug', 'department_director')->value('id'),
        ]);
        $dept = Department::create(['company_id' => $this->company->id, 'name' => 'Продажи', 'code' => 'SALES']);
        $manager->update(['department_id' => $dept->id]);
        $dept->refresh();
        $dept->update(['director_id' => $manager->id]);

        // заявитель сидит в том же отделе — иначе department-scope директора не пропустит
        $this->adminEmployee->update(['department_id' => $dept->id]);

        $this->post('/leaves', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-10',
            'type' => 'vacation',
            'reason' => 'Ежегодный отпуск',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $this->adminEmployee->id,
            'status' => 'pending',
        ]);

        $leave = LeaveRequest::query()->first();

        // руководитель согласовывает
        $managerUser = $manager->user;
        $this->actingAs($managerUser);

        $this->put('/leaves/'.$leave->id.'/decision', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'approved', 'approver_id' => $manager->id]);
    }

    /* ---------------- задачи ---------------- */

    public function test_task_create_show_delegate_and_status(): void
    {
        $worker = $this->employee('W-1', 'Иван', 'Иванов');
        EmployeeRole::create([
            'employee_id' => $worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
        $department = Department::create(['company_id' => $this->company->id, 'name' => 'IT', 'code' => 'IT']);

        $this->post('/tasks', [
            'title' => 'Инвентаризация техники',
            'type' => 'task',
            'priority' => 'medium',
            'assignee_type' => 'employee',
            'employee_id' => $worker->id,
        ])->assertRedirect();

        $task = Task::query()->where('title', 'Инвентаризация техники')->first();
        $this->assertNotNull($task);

        // карточка
        $this->get('/tasks/'.$task->id)->assertOk()->assertSee('Исполнители');

        // статус in_progress от исполнителя
        $this->actingAs($worker->user);
        $this->put('/tasks/'.$task->id.'/status', ['status' => 'in_progress'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('in_progress', $task->fresh()->status->value);

        // делегирование другому сотруднику
        $another = $this->employee('W-2', 'Пётр', 'Петров');
        $assignmentId = TaskAssignment::query()->where('task_id', $task->id)->value('id');

        $this->post('/tasks/'.$task->id.'/delegate', [
            'assignment_id' => $assignmentId,
            'to_employee_id' => $another->id,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignmentId,
            'employee_id' => $another->id,
            'assignment_status' => 'accepted',
        ]);
    }

    /* ---------------- C4: Kanban для тач-устройств ---------------- */

    public function test_kanban_card_renders_move_menu_without_current_status(): void
    {
        // единственная задача в доске — её карточка лежит в колонке «Новые»
        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Канбан-задача для тач-переноса',
            'number' => 'T-C4-1',
            'author_id' => $this->adminEmployee->id,
            'status' => 'new',
            'priority' => 'medium',
        ]);

        $html = (string) $this->get('/tasks')->assertOk()->getContent();

        // кнопка меню на карточке (тач-альтернатива drag&drop)
        $this->assertStringContainsString('data-kanban-move', $html);
        $this->assertSame(1, substr_count($html, 'data-kanban-move'), 'Одна карточка — одна кнопка переноса');

        // подсказка об альтернативе переноса видна (в т.ч. на мобильных)
        $this->assertStringContainsString('тач-устройства', $html);

        // меню содержит все колонки, кроме текущей
        foreach (['planned', 'in_progress', 'review', 'returned', 'completed', 'cancelled', 'overdue'] as $status) {
            $this->assertStringContainsString('data-move-to="'.$status.'"', $html, "Пункт меню {$status} отсутствует");
        }
        $this->assertStringNotContainsString('data-move-to="new"', $html, 'Текущая колонка не должна быть в меню');
    }

    public function test_kanban_move_menu_status_endpoint_supports_xhr_json(): void
    {
        $task = Task::create([
            'company_id' => $this->company->id,
            'title' => 'Задача для JSON-переноса',
            'number' => 'T-C4-2',
            'author_id' => $this->adminEmployee->id,
            'status' => 'new',
            'priority' => 'medium',
        ]);

        // тот же контракт, что шлёт drag&drop и меню переноса
        $this->put('/tasks/'.$task->id.'/status', ['status' => 'in_progress'], [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status', 'in_progress');

        $this->assertSame('in_progress', $task->fresh()->status->value);

        // ошибки приходят JSON-ом (меню показывает их в тосте, а не молча)
        $this->put('/tasks/'.$task->id.'/status', ['status' => 'no-such-status'], [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    /* ---------------- посещаемость UI ---------------- */

    public function test_attendance_timesheet_renders_grid(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => $this->adminEmployee->id,
            'date' => '2026-11-01',
            'clock_in_at' => '2026-11-01 08:02:00',
            'clock_out_at' => '2026-11-01 17:03:00',
            'status' => 'on_time',
            'worked_minutes' => 481,
        ]);

        $this->get('/attendance/timesheet')->assertOk()->assertSee('Табель');

        // чужой сотрудник для super_admin тоже доступен
        $other = $this->employee('X-9', 'Мария', 'Чужих');
        $this->get('/attendance/timesheet?employee_id='.$other->id)->assertOk();

        $this->get('/attendance/anomalies')->assertOk();
    }

    /* ---------------- уведомления UI ---------------- */

    public function test_notifications_list_and_mark_read(): void
    {
        AppNotification::create([
            'recipient_id' => $this->adminEmployee->id,
            'type' => 'test',
            'title' => 'Тестовое уведомление',
            'channel' => 'app',
        ]);

        $this->get('/notifications')->assertOk()->assertSee('Тестовое уведомление');

        $notification = AppNotification::query()->first();

        $this->put('/notifications/'.$notification->id.'/read')
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /* ---------------- permissions через middleware ---------------- */

    public function test_regular_employee_sees_403_on_admin_function(): void
    {
        $plainUser = User::factory()->create();
        $plain = $this->employee('P-1', 'Пётр', 'Простой', userId: $plainUser->id);
        EmployeeRole::create([
            'employee_id' => $plain->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($plainUser);

        // employees.manage_super_admin-only функционал
        $this->post('/positions', ['name' => 'Новая', 'code' => 'NEW1'])
            ->assertForbidden(); // 'positions.manage' отсутствует у роли employee

        // а сотрудники видны обычным (employees.view = own ok для всей страницы? нет: 403 нет)
        $this->get('/leaves')->assertOk();
    }
}
