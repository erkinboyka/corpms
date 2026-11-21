<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Projects\Services\ProjectService;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Shared\Enums\ProjectMemberRole;
use App\Shared\Enums\ProjectStatus;
use App\Support\EntityPhotoService;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;

/**
 * Проекты (Этап E5): портфель, карточка проекта, статусы и связь с задачами.
 *
 * Бюджет проекта показывает план и факт расходов из финансов; прогресс
 * считается по завершённым задачам (ProjectService).
 */
class ProjectController extends Controller
{
    public function __construct(
        protected ProjectService $projects,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Портфель проектов с фильтрами. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $status = (string) $request->query('status', 'open');
        $search = trim((string) $request->query('q'));

        $allowed = ['open', 'planned', 'active', 'on_hold', 'completed', 'cancelled', 'all'];

        if (in_array($status, $allowed, true) === false) {
            $status = 'open';
        }

        $projects = Project::query()
            ->where('company_id', $companyId)
            ->with(['manager', 'department'])
            ->withCount(['members', 'tasks'])
            ->when($status === 'open', static fn ($query) => $query->open())
            ->when($status !== 'open' && $status !== 'all', static fn ($query) => $query->where('status', $status))
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(static function ($sub) use ($search): void {
                    $sub->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('projects.index', [
            'projects' => $projects,
            'status' => $status,
            'search' => $search,
            'metrics' => $this->projects->metrics($companyId),
            'statuses' => ProjectStatus::cases(),
            'employees' => $this->employees($companyId),
            'departments' => $this->departments($companyId),
            'canManage' => $this->permissions->check($request->user(), 'projects.manage'),
        ]);
    }

    /** Карточка проекта: задачи, участники, вехи, бюджет. */
    public function show(Request $request, Project $project): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);

        $project->load([
            'manager',
            'department',
            'members.employee',
            'milestones',
            'tasks' => static fn ($query) => $query->orderByDesc('id')->limit(100),
        ]);

        return view('projects.show', [
            'project' => $project,
            'progress' => $this->projects->progress($project),
            'budget' => $this->projects->budget($project),
            'metrics' => $this->projects->metrics((int) $me->company_id),
            'attachableTasks' => $this->projects->attachableTasks((int) $me->company_id),
            'employees' => $this->employees((int) $me->company_id),
            'departments' => $this->departments((int) $me->company_id),
            'statuses' => ProjectStatus::cases(),
            'roles' => ProjectMemberRole::cases(),
            'canManage' => $this->permissions->check($request->user(), 'projects.manage'),
        ]);
    }

    /** Создание проекта. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['name' => __('У сотрудника не указана компания')])->withInput();
        }

        $validated = $this->validateProject($request, (int) $me->company_id, true);
        $this->guardReferences($validated, $me);

        $project = Project::query()->create([
            'company_id' => $me->company_id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => ProjectStatus::from($validated['status'] ?? ProjectStatus::Planned->value),
            'manager_id' => $validated['manager_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'budget' => $validated['budget'] ?? 0,
            'created_by' => $me->user_id,
        ]);

        app(EntityPhotoService::class)->sync($project, $request, 'projects');

        $this->audit->log($request->user(), 'project.created', 'project', $project->id, null, [
            'code' => $project->code,
            'name' => $project->name,
        ]);

        return redirect()->route('projects.show', $project)->with('status', __('Проект «').$project->name.__('» создан'));
    }

    /** Изменение карточки проекта. */
    public function update(Request $request, Project $project): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);

        $validated = $this->validateProject($request, (int) $me->company_id, false);
        $this->guardReferences($validated, $me);

        $before = ['name' => $project->name, 'budget' => (string) $project->budget];
        $project->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'manager_id' => $validated['manager_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'budget' => $validated['budget'] ?? 0,
        ])->save();

        app(EntityPhotoService::class)->sync($project, $request, 'projects');

        $this->audit->log($request->user(), 'project.updated', 'project', $project->id, $before, [
            'name' => $project->name,
            'budget' => (string) $project->budget,
        ]);

        return back()->with('status', __('Проект «').$project->name.__('» обновлён'));
    }

    /** Смена статуса проекта. */
    public function status(Request $request, Project $project): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProjectStatus::class)],
        ], [], ['status' => __('статус')]);

        $from = $project->status;
        $project->status = ProjectStatus::from($validated['status']);
        $project->save();

        $this->audit->log($request->user(), 'project.status_changed', 'project', $project->id, [
            'status' => $from->value,
        ], [
            'status' => $project->status->value,
        ]);

        return back()->with('status', __('Статус проекта: «').$this->statusLabel($project->status).'»');
    }

    /** Привязка существующей задачи к проекту. */
    public function attachTask(Request $request, Project $project): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);

        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
        ], [], ['task_id' => __('задача')]);

        TenantGuard::ensureReference('tasks', (int) $validated['task_id'], $me);

        $task = Task::query()->where('company_id', $me->company_id)->findOrFail((int) $validated['task_id']);
        $this->projects->attachTask($project, $task);

        $this->audit->log($request->user(), 'project.task_attached', 'project', $project->id, null, [
            'task_id' => $task->id,
        ]);

        return back()->with('status', __('Задача «').$task->title.__('» привязана к проекту'));
    }

    /** Отвязка задачи от проекта. */
    public function detachTask(Request $request, Project $project, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);
        TenantGuard::ensure($task, $me);

        try {
            $this->projects->detachTask($project, $task);
        } catch (LogicException $exception) {
            return back()->withErrors(['task_id' => $exception->getMessage()]);
        }

        $this->audit->log($request->user(), 'project.task_detached', 'project', $project->id, null, [
            'task_id' => $task->id,
        ]);

        return back()->with('status', __('Задача «').$task->title.__('» отвязана от проекта'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProject(Request $request, int $companyId, bool $withCode): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'manager_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'photo' => app(EntityPhotoService::class)->rules(),
            'reset_photo' => ['nullable', 'boolean'],
        ];

        if ($withCode) {
            $rules['code'] = [
                'required', 'string', 'max:32',
                Rule::unique('projects', 'code')->where('company_id', $companyId),
            ];
            $rules['status'] = ['nullable', Rule::enum(ProjectStatus::class)];
        }

        return $request->validate($rules, [], ['name' => __('название'), 'code' => __('код')]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function guardReferences(array $validated, Employee $me): void
    {
        foreach (['manager_id' => 'employees', 'department_id' => 'departments'] as $field => $table) {
            $value = $validated[$field] ?? null;

            if ($value !== null && $value !== '') {
                TenantGuard::ensureReference($table, (int) $value, $me);
            }
        }
    }

    private function statusLabel(ProjectStatus $status): string
    {
        return match ($status) {
            ProjectStatus::Planned => __('Запланирован'),
            ProjectStatus::Active => __('В работе'),
            ProjectStatus::OnHold => __('Приостановлен'),
            ProjectStatus::Completed => __('Завершён'),
            ProjectStatus::Cancelled => __('Отменён'),
        };
    }

    /** @return Collection<int, Employee> */
    private function employees(int $companyId): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'middle_name']);
    }

    /** @return Collection<int, Department> */
    private function departments(int $companyId): Collection
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();
    }
}
