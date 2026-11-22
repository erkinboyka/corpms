<?php

declare(strict_types=1);

namespace App\Domains\Tasks\Services;

use App\Domains\Audit\Services\AuditService;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskDelegation;
use App\Models\TaskResult;
use App\Models\TaskStatusHistory;
use App\Models\TaskTimeLog;
use App\Shared\Enums\AssigneeType;
use App\Shared\Enums\TaskAssignmentStatus;
use App\Shared\Enums\TaskPriority;
use App\Shared\Enums\TaskStatus;
use App\Shared\Enums\TaskType;
use App\Support\Concurrency;
use App\Support\NumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Движок задач/поручений (§12–15 ТЗ):
 *  -я карарахия: Director → Department Director → Employee;
 *  - назначение: сотруднику / отделу / нескольким / партициональные subtask;
 *  - наследование subtask (project, department, author, priority, deadline);
 *  - делегирование;
 *  - time tracking (START/STOP) — отдельный показатель от attendance.
 */
class TaskService
{
    public const MAX_SUBTASK_DEPTH = 3; // настраивается в settings (§15)

    /**
     * BL-17: разрешённые переходы статусов. completed/cancelled — финальные,
     * возврат в работу возможен только из review/returned/overdue.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'new' => ['planned', 'in_progress', 'review', 'completed', 'cancelled', 'overdue'],
        'planned' => ['in_progress', 'review', 'completed', 'cancelled', 'overdue'],
        'in_progress' => ['in_progress', 'review', 'returned', 'completed', 'cancelled', 'overdue'],
        'review' => ['review', 'in_progress', 'returned', 'completed', 'cancelled'],
        'returned' => ['in_progress', 'completed', 'cancelled'],
        'overdue' => ['in_progress', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        protected AuditService $audit,
        protected NumberGenerator $numbers,
    ) {}

    /* -------------------- создать -------------------- */

    public function create(Employee $author, array $data): Task
    {
        $task = DB::transaction(function () use ($author, $data) {
            $task = Task::create([
                'parent_id' => $data['parent_id'] ?? null,
                'company_id' => $author->company_id,
                'department_id' => $data['department_id'] ?? $author->department_id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? TaskType::Task->value,
                'priority' => $data['priority'] ?? TaskPriority::Medium->value,
                'deadline' => $data['deadline'] ?? null,
                'author_id' => $author->id,
                'status' => TaskStatus::New->value,
                'number' => $this->nextNumber($author->company_id),
            ]);

            $assignees = $data['assignees'] ?? []; // [employee|dept| [employee_ids...]]
            $this->applyAssignments($task, $assignees);

            TaskStatusHistory::create([
                'task_id' => $task->id,
                'employee_id' => $author->id,
                'to_status' => $task->status->value,
                'comment' => __('Задача создана'),
            ]);

            $this->audit->log($author->user()->first(), 'task.created', 'task', $task->id, null, [
                'title' => $task->title,
                'type' => $task->type->value,
                'assignees' => collect($assignees)->map(fn ($a) => $a)->all(),
            ]);

            return $task;
        });

        return $task;
    }

    /** §14: массовое назначение (нескольких отделов и сотрудников одновременно). */
    public function applyAssignments(Task $task, array $assignees): void
    {
        foreach ($assignees as $assignee) {
            if (isset($assignee['department_id'])) {
                $this->assignToDepartment($task, (int) $assignee['department_id']);
            } elseif (isset($assignee['employee_ids']) && count($assignee['employee_ids']) > 0) {
                foreach ($assignee['employee_ids'] as $employeeId) {
                    $this->assignToEmployee($task, (int) $employeeId);
                }
            } elseif (isset($assignee['employee_id'])) {
                $this->assignToEmployee($task, (int) $assignee['employee_id']);
            }
        }
    }

    /** Назначение отделу: раздел задачи создаётся всем активным сотрудникам отдела без руководителя? нет: руководителю отдела (§13 вариант 2).
     *  Плюс пропагируется на supervisor'а, который делегирует специалистам private subtask. */
    public function assignToDepartment(Task $task, int $departmentId): TaskAssignment
    {
        $department = Department::query()->whereKey($departmentId)->firstOrFail();

        // Межтенантная изоляция (P0): отдел обязан принадлежать компании задачи.
        if ((int) $department->company_id !== (int) $task->company_id) {
            abort(403, __('Отдел принадлежит другой компании'));
        }

        $assigneeId = $department->director_id
            ?? $department->employees()->where('status', 'active')->value('id');

        return TaskAssignment::create([
            'task_id' => $task->id,
            'assignee_type' => AssigneeType::Department,
            'department_id' => $department->id,
            'employee_id' => $assigneeId,
            'assignment_status' => TaskAssignmentStatus::Pending,
        ]);
    }

    public function assignToEmployee(Task $task, int $employeeId): TaskAssignment
    {
        $employee = Employee::query()->findOrFail($employeeId);

        // Межтенантная изоляция (P0): исполнитель из компании задачи.
        if ((int) $employee->company_id !== (int) $task->company_id) {
            abort(403, __('Сотрудник принадлежит другой компании'));
        }

        return TaskAssignment::create([
            'task_id' => $task->id,
            'assignee_type' => AssigneeType::Employee,
            'employee_id' => $employee->id,
            'assignment_status' => TaskAssignmentStatus::Pending,
        ]);
    }

    /* -------------------- subtask (§15) -------------------- */

    /** Employee создаёт подзадачу внутри полученной/department задачи; наследует базу, но исполняeтся другой сотрудник. */
    public function createSubtask(Employee $author, Task $parent, array $data): Task
    {
        if ($this->depth($parent) + 1 > self::MAX_SUBTASK_DEPTH) {
            throw new LogicException('Достигнут предел вложенности подзадач ('.self::MAX_SUBTASK_DEPTH.')');
        }

        $subtask = DB::transaction(function () use ($author, $parent, $data) {
            $subtask = Task::create([
                'parent_id' => $parent->id,
                'company_id' => $parent->company_id,
                'department_id' => $data['department_id'] ?? $parent->department_id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? $parent->type,
                'priority' => $data['priority'] ?? $parent->priority,
                'deadline' => $data['deadline'] ?? $parent->deadline,
                'author_id' => $author->id,
                'status' => TaskStatus::New,
                'number' => $this->nextNumber($author->company_id),
            ]);

            TaskStatusHistory::create([
                'task_id' => $subtask->id,
                'employee_id' => $author->id,
                'to_status' => TaskStatus::New->value,
                'comment' => 'Создана подзадача от '.$parent->title,
            ]);

            if (isset($data['employee_id'])) {
                $this->assignToEmployee($subtask, (int) $data['employee_id']);
            }

            return $subtask;
        });

        return $subtask;
    }

    /* -------------------- делегирование (§13) -------------------- */

    public function delegate(Employee $fromEmployee, TaskAssignment $assignment, Employee $toEmployee, ?string $reason = null): TaskDelegation
    {
        // BL-20: delegation, смена исполнителя и аудит — одна транзакция;
        // блокировка assignment не даёт двум delegations «перейти» в разных потоках.
        return DB::transaction(function () use ($fromEmployee, $assignment, $toEmployee, $reason): TaskDelegation {
            $assignment = TaskAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            $delegation = TaskDelegation::create([
                'task_id' => $assignment->task_id,
                'assignment_id' => $assignment->id,
                'from_employee_id' => $fromEmployee->id,
                'to_employee_id' => $toEmployee->id,
                'reason' => $reason,
            ]);

            $assignment->update([
                'employee_id' => $toEmployee->id,
                'assignment_status' => TaskAssignmentStatus::Accepted,
                'acknowledged_at' => now(),
            ]);

            if ($assignment->task->department_id === null || $assignment->task->department_id !== $toEmployee->department_id) {
                // делегация вне отдела = author видит тряхнуться (audit)
                $this->audit->log(
                    $fromEmployee->user()->first(),
                    'task.delegated',
                    'task_assignment',
                    $assignment->id,
                    null,
                    ['to_employee_id' => $toEmployee->id, 'reason' => $reason]
                );
            }

            return $delegation;
        });
    }

    /* -------------------- статусы (§39,45,47) -------------------- */

    public function changeStatus(Employee $actor, Task $task, TaskStatus $status, ?string $comment = null): Task
    {
        $from = $task->status;
        if (! in_array($status->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new LogicException(__('Переход статуса задачи не разрешён: '.$from->value.' → '.$status->value));
        }

        $task->update([
            'status' => $status,
            'completed_at' => $status === TaskStatus::Completed ? now() : $task->completed_at,
        ]);

        TaskStatusHistory::create([
            'task_id' => $task->id,
            'employee_id' => $actor->id,
            'from_status' => $from->value,
            'to_status' => $status->value,
            'comment' => $comment,
        ]);

        $this->audit->log(
            $actor->user()->first(),
            'task.status_changed',
            'task',
            $task->id,
            ['status' => $from->value],
            ['status' => $status->value, 'comment' => $comment]
        );

        // Если департаментская задача завершена — агрегируем в родителя (§14)
        if ($status === TaskStatus::Completed) {
            $this->maybeCompleteParent($task);
        }

        return $task;
    }

    /** Все subtask'и родителя завершены → перевести родителя в review (собрать общий результат). */
    public function maybeCompleteParent(Task $task): void
    {
        if ($task->parent_id === null) {
            return;
        }

        $parent = Task::query()->find($task->parent_id);
        $previousStatus = $parent->status;
        $unfinishedSiblings = Task::query()
            ->where('parent_id', $parent->id)
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->count();

        if ($unfinishedSiblings === 0 && in_array($previousStatus, [TaskStatus::New, TaskStatus::Planned, TaskStatus::InProgress], true)) {
            $parent->update(['status' => TaskStatus::Review]);
            TaskStatusHistory::create([
                'task_id' => $parent->id,
                'employee_id' => null,
                'from_status' => $previousStatus->value,
                'to_status' => TaskStatus::Review->value,
                'comment' => __('Все части департаментских задач приняты — сводка готова'),
            ]);
        }
    }

    public function submitResult(Employee $employee, Task $task, ?string $summary): void
    {
        // BL-18: результат может сдать только исполнитель или автор задачи.
        $isAssignee = TaskAssignment::query()
            ->where('task_id', $task->id)
            ->where('employee_id', $employee->id)
            ->exists();
        if (! $isAssignee && (int) $task->author_id !== (int) $employee->id) {
            throw new LogicException(__('Отправить результат может только исполнитель или автор задачи'));
        }

        DB::transaction(function () use ($employee, $task, $summary) {
            TaskResult::updateOrCreate(
                ['task_id' => $task->id, 'submitted_by' => $employee->id],
                ['summary' => $summary],
            );

            $this->changeStatus($employee, $task, TaskStatus::Review, __('Отправлена на проверку'));
        });
    }

    /* -------------------- time tracking (§36 ТЗ) -------------------- */

    public function startTimeTracking(Employee $employee, Task $task): ?TaskTimeLog
    {
        $open = TaskTimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereNull('stopped_at')
            ->first();

        if ($open !== null) {
            // уже идёт учёт по другой задаче: закрыть его
            $open->stopped_at = now();
            $open->minutes = (int) $open->started_at->diffInMinutes($open->stopped_at, true);
            $open->save();
        }

        try {
            return TaskTimeLog::create([
                'task_id' => $task->id,
                'employee_id' => $employee->id,
                'started_at' => now(),
                'stopped_at' => null,
                'minutes' => 0,
            ]);
        } catch (QueryException $exception) {
            // H-BL-04: два параллельных START — победивший уже создал открытый
            // лог (unique-generated key). Возвращаем его идемпотентно.
            if (! Concurrency::isUniqueViolation($exception)) {
                throw $exception;
            }

            return TaskTimeLog::query()
                ->where('employee_id', $employee->id)
                ->whereNull('stopped_at')
                ->orderByDesc('id')
                ->firstOrFail();
        }
    }

    public function stopTimeTracking(Employee $employee, Task $task): ?TaskTimeLog
    {
        $log = TaskTimeLog::query()
            ->where('employee_id', $employee->id)
            ->where('task_id', $task->id)
            ->whereNull('stopped_at')
            ->orderByDesc('id')
            ->first();

        if ($log === null) {
            return null;
        }

        $log->stopped_at = now();
        $log->minutes = (int) $log->started_at->diffInMinutes($log->stopped_at, true);
        $log->save();

        return $log;
    }

    /** Глубина родительской цепочки: root = 1. */
    public function depth(Task $task, int $maxDepth = 10): int
    {
        $depth = 1;
        $current = $task;

        while ($current->parent_id !== null && $depth < $maxDepth) {
            $current = Task::query()->find($current->parent_id);
            if ($current === null) {
                break;
            }
            $depth++;
        }

        return $depth;
    }

    public function totalTrackedMinutes(Task $task): int
    {
        return (int) TaskTimeLog::query()->where('task_id', $task->id)->sum('minutes');
    }

    /* -------------------- overdue (§44 ТЗ) -------------------- */

    /**
     * Пометить задачи просроченными (шедуляр).
     *
     * BL-19: просрочиваются все открытые статусы, а не только in_progress,
     * и каждый переход фиксируется в истории задачи (UI показывает overdue
     * по дедлайну, поэтому БД не должна расходиться с rabотой интерфейса).
     */
    public function markOverdue(): int
    {
        $openStatuses = ['new', 'planned', 'in_progress', 'review', 'returned'];

        $tasks = Task::query()
            ->whereIn('status', $openStatuses)
            ->where('deadline', '<=', now()->format('Y-m-d H:i:s'))
            ->whereNull('completed_at')
            ->get();

        foreach ($tasks as $task) {
            $from = $task->status->value;
            $task->update(['status' => TaskStatus::Overdue->value]);
            TaskStatusHistory::create([
                'task_id' => $task->id,
                'employee_id' => null,
                'from_status' => $from,
                'to_status' => TaskStatus::Overdue->value,
                'comment' => __('Просрочено автоматически'),
            ]);
        }

        return $tasks->count();
    }

    public function nextNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $prefix = 'TSK-'.$year.'-';

        // BL-07/DB-08: нумерация скоуплена по компании и выдаётся через
        // атомарную последовательность, а не через COUNT()+1.
        $value = $this->numbers->next('task.number.'.$companyId, $year, fn () => $this->numbers->maxSuffix(
            $prefix,
            Task::query()->withTrashed()
                ->where('company_id', $companyId)
                ->where('number', 'like', $prefix.'%')
                ->pluck('number'),
        ));

        return sprintf('%s%06d', $prefix, $value);
    }
}
