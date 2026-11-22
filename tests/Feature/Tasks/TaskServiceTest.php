<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Tasks\Services\TaskService;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResult;
use App\Models\TaskStatusHistory;
use App\Models\TaskTimeLog;
use App\Models\User;
use App\Shared\Enums\AssigneeType;
use App\Shared\Enums\PermissionScope;
use App\Shared\Enums\TaskAssignmentStatus;
use App\Shared\Enums\TaskPriority;
use App\Shared\Enums\TaskStatus;
use App\Support\NumberGenerator;
use App\Support\PermissionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * ТЗ §12–15: движок задач — назначение, подзадачи, делегирование,
 * статусы,RESULTS-сводка, time tracking, overdue.
 */
class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TaskService $service;

    protected Company $company;

    protected Department $itDept;

    protected Employee $director;

    protected Employee $worker;

    protected Employee $worker2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = new TaskService(app(AuditService::class), app(NumberGenerator::class));

        $this->company = Company::create(['name' => 'Test Co', 'code' => 'testco']);

        $this->itDept = Department::create([
            'company_id' => $this->company->id,
            'name' => 'IT',
            'code' => 'IT',
        ]);

        $this->director = $this->makeEmployee('DIR-1', 'Олег', 'Директоров', $this->itDept->id);
        $this->worker = $this->makeEmployee('WRK-1', 'Иван', 'Иванов', $this->itDept->id);
        $this->worker2 = $this->makeEmployee('WRK-2', 'Пётр', 'Петров', $this->itDept->id);

        $this->itDept->update(['director_id' => $this->director->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00'));
    }

    /* ---------------- создание и нумерация ---------------- */

    public function test_create_makes_task_with_number_and_history(): void
    {
        $task = $this->service->create($this->director, [
            'title' => 'Провести аудит инфраструктуры',
            'description' => 'Серверы и сеть',
            'deadline' => '2026-11-15 18:00:00',
            'priority' => 'high',
            'assignees' => [['employee_id' => $this->worker->id]],
        ]);

        $this->assertSame('TSK-2026-000001', $task->number);
        $this->assertSame(TaskStatus::New, $task->status);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertEquals('2026-11-15 18:00:00', $task->deadline->format('Y-m-d H:i:s'));

        // назначение сотруднику
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'assignee_type' => 'employee',
            'employee_id' => $this->worker->id,
            'assignment_status' => 'pending',
        ]);

        // истории статусов: запись о создании
        $this->assertDatabaseHas('task_status_history', [
            'task_id' => $task->id,
            'to_status' => 'new',
        ]);

        // аудит записан (п.60)
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'task.created',
            'object_id' => $task->id,
        ]);
    }

    public function test_multiple_assignees_each_get_assignment(): void
    {
        $task = $this->service->create($this->director, [
            'title' => 'Обновить парк компьютеров',
            'assignees' => [['employee_ids' => [$this->worker->id, $this->worker2->id]]],
        ]);

        $this->assertEquals(2, TaskAssignment::query()->where('task_id', $task->id)->count());
    }

    public function test_assign_department_targets_director(): void
    {
        $task = $this->service->create($this->director, [
            'title' => 'Подготовить план обучения',
            'assignees' => [['department_id' => $this->itDept->id]],
        ]);

        $assignment = TaskAssignment::query()->where('task_id', $task->id)->first();

        $this->assertSame(AssigneeType::Department, $assignment->assignee_type);
        $this->assertEquals($this->itDept->id, $assignment->department_id);
        $this->assertEquals($this->director->id, $assignment->employee_id);
    }

    /* ---------------- подзадачи (§15) ---------------- */

    public function test_subtask_inherits_deadline_and_priority(): void
    {
        $parent = $this->service->create($this->director, [
            'title' => 'Аудит отделов',
            'priority' => 'high',
            'deadline' => '2026-12-01 18:00:00',
        ]);

        $subtask = $this->service->createSubtask($this->director, $parent, [
            'title' => 'Аудит IT-отдела',
            'employee_id' => $this->worker->id,
        ]);

        $this->assertEquals($parent->id, $subtask->parent_id);
        $this->assertSame(TaskPriority::High, $subtask->priority);
        $this->assertEquals($parent->deadline->format('Y-m-d H:i:s'), $subtask->deadline->format('Y-m-d H:i:s'));
        $this->assertSame('TSK-2026-000002', $subtask->number);
    }

    public function test_subtask_depth_limit_enforced(): void
    {
        $parent = $this->service->create($this->director, ['title' => 'Уровень 1']);      // depth 1
        $l2 = $this->service->createSubtask($this->director, $parent, ['title' => 'Уровень 2']);     // depth 2
        $l3 = $this->service->createSubtask($this->director, $l2, ['title' => 'Уровень 3']);         // depth 3

        $this->assertEquals(3, $this->service->depth($l3));

        $this->expectException(LogicException::class);
        $this->service->createSubtask($this->director, $l3, ['title' => 'Уровень 4']);
    }

    /* ---------------- делегирование (§13) ---------------- */

    public function test_delegate_moves_assignment_to_new_employee(): void
    {
        $task = $this->service->create($this->director, [
            'title' => 'Собрать статистику',
            'assignees' => [['employee_id' => $this->director->id]],
        ]);

        $assignment = TaskAssignment::query()->where('task_id', $task->id)->first();

        $delegation = $this->service->delegate($this->director, $assignment, $this->worker, 'Загружен отчётами');

        $this->assertDatabaseHas('task_delegations', [
            'id' => $delegation->id,
            'from_employee_id' => $this->director->id,
            'to_employee_id' => $this->worker->id,
        ]);

        $assignment->refresh();
        $this->assertEquals($this->worker->id, $assignment->employee_id);
        $this->assertSame(TaskAssignmentStatus::Accepted, $assignment->assignment_status);
        $this->assertNotNull($assignment->acknowledged_at);
    }

    /* ---------------- статусы и сводка (§39,45,47) ---------------- */

    public function test_change_status_records_history_and_completed_at(): void
    {
        $task = $this->service->create($this->worker, ['title' => 'Задача']);

        $this->service->changeStatus($this->worker, $task, TaskStatus::InProgress);
        $this->service->changeStatus($this->worker, $task->fresh(), TaskStatus::Completed, 'Готово');

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->completed_at);

        $history = Task::query()->find($task->id)->statusHistory()->orderBy('id')->get();
        $this->assertCount(3, $history);
        $this->assertSame('in_progress', $history->get(2)->from_status);
        $this->assertSame('completed', $history->get(2)->to_status);
    }

    public function test_all_subtasks_completed_moves_parent_to_review(): void
    {
        $parent = $this->service->create($this->director, ['title' => 'Сводный отчёт']);
        $s1 = $this->service->createSubtask($this->director, $parent, ['title' => 'Часть IT']);
        $s2 = $this->service->createSubtask($this->director, $parent, ['title' => 'Часть HR']);

        $this->service->changeStatus($this->worker, $s1->fresh(), TaskStatus::Completed);

        // первая завершена, вторая нет — родитель не трогаем
        $this->assertSame(TaskStatus::New, $parent->fresh()->status);

        $this->service->changeStatus($this->worker2, $s2->fresh(), TaskStatus::Completed);

        $parent->refresh();
        $this->assertSame(TaskStatus::Review, $parent->status);
        $this->assertDatabaseHas('task_status_history', [
            'task_id' => $parent->id,
            'from_status' => 'new',
            'to_status' => 'review',
        ]);
    }

    public function test_submit_result_records_result_and_review(): void
    {
        $task = $this->service->create($this->worker, ['title' => 'Отчёт X']);
        $this->service->changeStatus($this->worker, $task->fresh(), TaskStatus::InProgress);

        $this->service->submitResult($this->worker, $task->fresh(), 'Отчёт готов: 42 страницы');

        $this->assertDatabaseHas('task_results', [
            'task_id' => $task->id,
            'submitted_by' => $this->worker->id,
        ]);
        $this->assertSame(TaskStatus::Review, $task->fresh()->status);
    }

    /* ---------------- time tracking (§36) ---------------- */

    public function test_time_tracking_start_and_stop(): void
    {
        $task = $this->service->create($this->worker, ['title' => 'Кодинг']);
        $other = $this->service->create($this->worker, ['title' => 'Другая']);

        $log = $this->service->startTimeTracking($this->worker, $task);
        $this->assertNull($log->stopped_at);

        Carbon::setTestNow(Carbon::parse('2026-10-29 10:30:00'));
        $this->service->stopTimeTracking($this->worker, $task);

        $log->refresh();
        $this->assertEquals(30, $log->minutes);
        $this->assertEquals(30, $this->service->totalTrackedMinutes($task));

        // новый запуск на другой задаче при открытом логе первой авто-закрывает его
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:35:00'));
        $this->service->startTimeTracking($this->worker, $other);

        $taskLog = TaskTimeLog::query()->where('task_id', $task->id)->orderByDesc('id')->first();
        $this->assertNotNull($taskLog->stopped_at);

        Carbon::setTestNow(Carbon::parse('2026-10-29 11:05:00'));
        $this->service->stopTimeTracking($this->worker, $other);

        $this->assertEquals(30, $this->service->totalTrackedMinutes($other));
    }

    /** H-BL-04: у сотрудника не может быть двух открытых интервалов START. */
    public function test_only_one_open_time_log_per_employee(): void
    {
        $task = $this->service->create($this->worker, ['title' => 'Первая задача']);
        $other = $this->service->create($this->worker, ['title' => 'Вторая задача']);

        self::assertTrue(Schema::hasIndex(
            'task_time_logs',
            'task_time_logs_one_open_per_employee',
        ));

        $this->service->startTimeTracking($this->worker, $task);
        $again = $this->service->startTimeTracking($this->worker, $other);

        self::assertNotNull($again);
        self::assertSame(1, TaskTimeLog::query()
            ->where('employee_id', $this->worker->id)
            ->whereNull('stopped_at')
            ->count());
    }

    public function test_delegate_is_atomic_when_audit_fails(): void
    {
        $task = $this->service->create($this->director, [
            'title' => 'Атомарное делегирование',
            'assignees' => [['employee_id' => $this->director->id]],
        ]);
        $task->update(['department_id' => null]); // вне отдела → вызывается audit
        $assignment = TaskAssignment::query()->where('task_id', $task->id)->firstOrFail();

        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit failed'));
        $service = new TaskService($audit, app(NumberGenerator::class));

        try {
            $service->delegate($this->director, $assignment, $this->worker, 'причина');
            $this->fail('Ожидалось исключение аудита');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit failed', $e->getMessage());
        }

        $this->assertDatabaseMissing('task_delegations', ['assignment_id' => $assignment->id]);
        $assignment->refresh();
        $this->assertEquals($this->director->id, $assignment->employee_id);
        $this->assertNull($assignment->acknowledged_at);
    }

    /* ---------------- overdue (§44) ---------------- */

    public function test_mark_overdue_flags_only_past_deadlines(): void
    {
        $late = Task::create([
            'company_id' => $this->company->id,
            'department_id' => $this->itDept->id,
            'title' => 'Просроченная',
            'number' => 'TSK-2026-000010',
            'author_id' => $this->director->id,
            'status' => 'new',
        ]);
        $this->service->changeStatus($this->worker, $late, TaskStatus::InProgress);
        $late->update(['deadline' => '2026-10-28 00:00:00']);

        $future = Task::create([
            'company_id' => $this->company->id,
            'department_id' => $this->itDept->id,
            'title' => 'Нормальная',
            'number' => 'TSK-2026-000011',
            'author_id' => $this->director->id,
            'status' => 'new',
            'deadline' => '2026-12-01 00:00:00',
        ]);
        $this->service->changeStatus($this->worker, $future, TaskStatus::InProgress);

        $count = $this->service->markOverdue();

        $this->assertEquals(1, $count);
        $this->assertSame(TaskStatus::Overdue, $late->fresh()->status);
        $this->assertSame(TaskStatus::InProgress, $future->fresh()->status);
    }

    public function test_mark_overdue_covers_all_open_statuses_and_writes_history(): void
    {
        $numbers = 100;
        $created = [];
        foreach (['new', 'planned', 'in_progress', 'review', 'returned'] as $status) {
            $created[$status] = Task::create([
                'company_id' => $this->company->id,
                'department_id' => $this->itDept->id,
                'title' => 'BL-19 '.$status,
                'number' => 'TSK-2026-'.++$numbers,
                'author_id' => $this->director->id,
                'status' => $status,
                'deadline' => '2026-10-28 00:00:00',
            ]);
        }
        $completed = Task::create([
            'company_id' => $this->company->id,
            'department_id' => $this->itDept->id,
            'title' => 'BL-19 completed',
            'number' => 'TSK-2026-'.++$numbers,
            'author_id' => $this->director->id,
            'status' => 'completed',
            'deadline' => '2026-10-28 00:00:00',
            'completed_at' => now(),
        ]);

        $count = $this->service->markOverdue();

        $this->assertSame(5, $count);
        foreach ($created as $status => $task) {
            $this->assertSame(TaskStatus::Overdue, $task->fresh()->status);
            $history = TaskStatusHistory::query()
                ->where('task_id', $task->id)
                ->where('from_status', $status)
                ->where('to_status', TaskStatus::Overdue->value)
                ->first();
            $this->assertNotNull($history);
        }
        $this->assertSame(TaskStatus::Completed, $completed->fresh()->status);
    }

    /* ---------------- permissions: department-scope vs own-scope (§49) ---------------- */

    public function test_task_permission_scopes_differ_by_role(): void
    {
        $permissions = app(PermissionService::class);

        $this->attachRole($this->director, 'department_director');
        $this->attachRole($this->worker, 'employee');

        // руководитель отдела: dept-скоуп
        $this->assertSame(
            PermissionScope::Department,
            $permissions->can($this->director->user, 'tasks.view')
        );
        // чтобы закрепить скоуп на объекте: основной задачей с тем же отделом — доступен
        $ownDeptTask = $this->service->create($this->director, ['title' => 'Своя']);
        $this->assertTrue(
            $ownDeptTask->department_id === $this->director->department_id
            && $permissions->check($this->director->user, 'tasks.create')
        );

        // рядовой сотрудник: own-скоуп
        $this->assertSame(
            PermissionScope::Own,
            $permissions->can($this->worker->user, 'tasks.view')
        );

        // dept-требование рядовому сотруднику недоступно, own-требование — доступно
        $this->assertFalse(
            $permissions->checkScope($this->worker->user, 'tasks.view', PermissionScope::Department)
        );
        $this->assertTrue(
            $permissions->checkScope($this->worker->user, 'tasks.view', PermissionScope::Own)
        );
    }

    public function test_status_transition_table_rejects_illegal_jump_without_history(): void
    {
        $task = $this->service->create($this->director, [
            'title' => 'Переходы статусов',
            'priority' => 'medium',
            'assignees' => [['employee_id' => $this->worker->id]],
        ]);

        $this->service->changeStatus($this->worker, $task->fresh(), TaskStatus::InProgress);
        $this->service->changeStatus($this->worker, $task->fresh(), TaskStatus::Completed);
        $historyBefore = TaskStatusHistory::query()->where('task_id', $task->id)->count();

        try {
            $this->service->changeStatus($this->worker, $task->fresh(), TaskStatus::New);
            $this->fail('Запрещённый переход completed → new должен быть отклонён');
        } catch (LogicException) {
            // ожидаемо
        }

        $this->assertSame('completed', $task->fresh()->status->value);
        $this->assertSame($historyBefore, TaskStatusHistory::query()->where('task_id', $task->id)->count());
    }

    public function test_submit_result_is_assignee_only_and_idempotent(): void
    {
        $task = $this->service->create($this->director, [
            'title' => 'Результат задачи',
            'priority' => 'medium',
            'assignees' => [['employee_id' => $this->worker->id]],
        ]);

        try {
            $this->service->submitResult($this->worker2, $task, 'чужой результат');
            $this->fail('Наблюдатель не должен сдавать результат');
        } catch (LogicException) {
            // ожидаемо
        }
        $this->assertSame(0, TaskResult::query()->count());

        $this->service->submitResult($this->worker, $task->fresh(), 'первый');
        $this->service->submitResult($this->worker, $task->fresh(), 'второй');

        $this->assertSame(1, TaskResult::query()->where('task_id', $task->id)->count());
        $this->assertSame('второй', TaskResult::query()->sole()->summary);
    }

    /* ---------------- helpers ---------------- */

    protected function attachRole(Employee $employee, string $roleSlug): void
    {
        $roleId = Role::query()->where('slug', $roleSlug)->value('id');

        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => $roleId,
        ]);
    }

    protected function makeEmployee(string $no, string $first, string $last, ?int $departmentId): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'department_id' => $departmentId,
        ]);
    }
}
