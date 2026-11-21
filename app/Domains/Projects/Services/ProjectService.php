<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Models\FinTransaction;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Shared\Enums\FinDirection;
use App\Shared\Enums\ProjectMemberRole;
use App\Shared\Enums\ProjectStatus;
use App\Shared\Enums\TaskStatus;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Проектный движок (Этап E5): прогресс по задачам, бюджет по финансам,
 * ресурсы (участники), вехи и привязка задач.
 *
 * Правила:
 *   - прогресс = завершённые задачи / все задачи проекта;
 *   - «факт» бюджета — расходные операции финансов с project_id (не хранится);
 *   - задача принадлежит одному проекту; чужая компания — отказ.
 */
class ProjectService
{
    /**
     * Метрики портфеля проектов компании.
     *
     * @return array<string, int|float>
     */
    public function metrics(int $companyId): array
    {
        $projects = Project::query()->where('company_id', $companyId)->get();

        $spent = (float) FinTransaction::query()
            ->where('company_id', $companyId)
            ->whereNotNull('project_id')
            ->where('direction', FinDirection::Expense->value)
            ->sum('amount');

        return [
            'projects' => $projects->count(),
            'active' => $projects->where('status', ProjectStatus::Active)->count(),
            'open' => $projects->filter(static fn (Project $project): bool => $project->status->isOpen())->count(),
            'overdue' => $projects->filter(static fn (Project $project): bool => $project->isOverdue())->count(),
            'completed' => $projects->where('status', ProjectStatus::Completed)->count(),
            'budget' => (float) $projects->filter(static fn (Project $project): bool => $project->status->isOpen())->sum('budget'),
            'spent' => $spent,
            'tasks_open' => Task::query()
                ->where('company_id', $companyId)
                ->whereNotNull('project_id')
                ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
                ->count(),
            'members' => ProjectMember::query()->where('company_id', $companyId)->distinct('employee_id')->count('employee_id'),
        ];
    }

    /**
     * Прогресс проекта по задачам.
     *
     * @return array{total:int, completed:int, percent:int}
     */
    public function progress(Project $project): array
    {
        $total = $project->tasks()->count();
        $completed = $project->tasks()->where('status', TaskStatus::Completed->value)->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
        ];
    }

    /**
     * Бюджет проекта: план, факт расходов, остаток и исполнение.
     *
     * @return array{planned:float, spent:float, received:float, remaining:float, percent:?float}
     */
    public function budget(Project $project): array
    {
        $spent = (float) $project->finTransactions()
            ->where('direction', FinDirection::Expense->value)
            ->sum('amount');

        $received = (float) $project->finTransactions()
            ->where('direction', FinDirection::Income->value)
            ->sum('amount');

        $planned = (float) $project->budget;

        return [
            'planned' => $planned,
            'spent' => $spent,
            'received' => $received,
            'remaining' => round($planned - $spent, 2),
            'percent' => $planned > 0 ? round($spent / $planned * 100, 1) : null,
        ];
    }

    /**
     * Добавление/обновление участника (ресурса) проекта.
     */
    public function addMember(Project $project, int $employeeId, ProjectMemberRole $role, int $allocationPercent): ProjectMember
    {
        return ProjectMember::query()->updateOrCreate(
            ['project_id' => $project->id, 'employee_id' => $employeeId],
            [
                'company_id' => $project->company_id,
                'role' => $role,
                'allocation_percent' => max(0, min(100, $allocationPercent)),
            ],
        );
    }

    public function removeMember(Project $project, ProjectMember $member): void
    {
        if ((int) $member->project_id !== (int) $project->id) {
            throw new LogicException(__('Участник не относится к проекту'));
        }

        $member->delete();
    }

    /**
     * Веха проекта: добавление.
     */
    public function addMilestone(Project $project, string $title, ?string $dueDate): ProjectMilestone
    {
        return $project->milestones()->create([
            'company_id' => $project->company_id,
            'title' => $title,
            'due_date' => $dueDate,
        ]);
    }

    /**
     * Отметка вехи: выполнена/возвращена в работу.
     */
    public function toggleMilestone(ProjectMilestone $milestone): ProjectMilestone
    {
        $milestone->completed_at = $milestone->completed_at === null ? now() : null;
        $milestone->save();

        return $milestone;
    }

    /**
     * Привязка задачи к проекту (перепривязка из другого проекта разрешена).
     */
    public function attachTask(Project $project, Task $task): Task
    {
        $task->project_id = $project->id;
        $task->save();

        return $task;
    }

    /**
     * Отвязка задачи от проекта.
     */
    public function detachTask(Project $project, Task $task): Task
    {
        if ((int) $task->project_id !== (int) $project->id) {
            throw new LogicException(__('Задача не относится к проекту'));
        }

        $task->project_id = null;
        $task->save();

        return $task;
    }

    /**
     * Свободные задачи компании, которые можно привязать (не завершены).
     *
     * @return Collection<int, Task>
     */
    public function attachableTasks(int $companyId, int $limit = 200): Collection
    {
        return Task::query()
            ->where('company_id', $companyId)
            ->whereNull('project_id')
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
