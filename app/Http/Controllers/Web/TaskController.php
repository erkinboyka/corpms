<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Tasks\Services\TaskService;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskTimeLog;
use App\Shared\Enums\PermissionScope;
use App\Shared\Enums\TaskStatus;
use App\Support\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Задачи в UI (п.38–49 ТЗ): списки по scope, авторская задача, статус,
 * подзадачи, делегирование, время, комментарии.
 */
class TaskController extends Controller
{
    public function __construct(
        protected TaskService $tasks,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /* -------------------- списки -------------------- */

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'tasks.view');

        $query = Task::query()->with(['author', 'department'])
            // межтенантная изоляция (P0): база списка — компания сотрудника
            ->where('company_id', $me->company_id);

        if ($scope === PermissionScope::Own) {
            // only own assigned or authored
            $query->where(function ($q) use ($me) {
                $q->where('author_id', $me->id)
                    ->orWhereIn('id', $this->assignedToMe($me)->select('task_id'));
            });
        } elseif ($scope === PermissionScope::Department) {
            $query->where(function ($q) use ($me) {
                $q->where('department_id', $me->department_id)
                    ->orWhere('author_id', $me->id)
                    ->orWhereIn('id', $this->assignedToMe($me)->select('task_id'));
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        // Доска/календарь: те же фильтры, но без пагинации (ограничение — защита от раздувания)
        $board = (clone $query)
            ->with(['author', 'department'])
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->groupBy(fn (Task $t) => $t->status->value);

        $tasks = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('tasks.index', compact('tasks', 'scope', 'status', 'priority', 'board'));
    }

    public function mine(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $assigned = Task::query()
            ->whereIn('id', $this->assignedToMe($me)->select('task_id'))
            ->with('author')
            ->orderByDesc('id')
            ->get();

        $written = Task::query()
            ->where('author_id', $me->id)
            ->with('author')
            ->orderByDesc('id')
            ->get();

        $openLogs = TaskTimeLog::query()
            ->where('employee_id', $me->id)
            ->whereNull('stopped_at')
            ->with('task')
            ->first();

        return view('tasks.mine', compact('assigned', 'written', 'openLogs'));
    }

    public function overdue(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'tasks.view');

        $query = Task::query()->with(['author'])
            ->where('company_id', $me->company_id)
            ->where('status', 'overdue');

        if ($scope === PermissionScope::Own) {
            $query->where(function ($q) use ($me) {
                $q->where('author_id', $me->id)
                    ->orWhereIn('id', $this->assignedToMe($me)->select('task_id'));
            });
        } elseif ($scope === PermissionScope::Department) {
            $query->where(function ($q) use ($me) {
                $q->where('department_id', $me->department_id)
                    ->orWhereIn('id', $this->assignedToMe($me)->select('task_id'));
            });
        }

        $tasks = $query->orderBy('deadline')->paginate(20)->withQueryString();

        return view('tasks.overdue', compact('tasks'));
    }

    /* -------------------- карточка -------------------- */

    public function show(Request $request, Task $task): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        // IDOR-фикс: карточка доступна только в пределах scope видимости
        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        $task->load([
            'author', 'department', 'parent', 'children.author',
            'assignments.employee', 'statusHistory.employee',
            'comments.employee', 'timeLogs.employee', 'results', 'delegations',
        ])->loadCount('attachments');

        $canCreateSubtask = (int) $task->depth <= TaskService::MAX_SUBTASK_DEPTH;
        $assignment = TaskAssignment::query()->where('task_id', $task->id)->where('employee_id', $me->id)->first();

        $statuses = TaskStatus::cases();

        $canEditTask = (int) ($task->author_id ?? 0) === (int) $me->id
            || $this->permissions->check($request->user(), 'tasks.manage');

        $canAttach = $this->canAttachToTask($request, $task, $me);
        $attachments = $task->attachments()->with('employee')->orderBy('id')->get();

        // учёт времени (п.49 ТЗ): открытый журнал текущего сотрудника, итог и история
        $openLog = $task->timeLogs->first(
            fn (TaskTimeLog $log): bool => $log->stopped_at === null && (int) $log->employee_id === (int) $me->id
        );
        $otherOpenLog = TaskTimeLog::query()
            ->where('employee_id', $me->id)
            ->whereNull('stopped_at')
            ->where('task_id', '!=', $task->id)
            ->with('task:id,title')
            ->first();
        // running-журнал ещё не закрыт (minutes = 0), поэтому сумма не задвоит идущий учёт
        $totalMinutes = (int) $task->timeLogs
            ->filter(fn (TaskTimeLog $log): bool => $log->stopped_at !== null)
            ->sum('minutes');
        $historyLogs = $task->timeLogs
            ->filter(fn (TaskTimeLog $log): bool => $log->stopped_at !== null)
            ->sortByDesc('started_at')
            ->take(10)
            ->values();

        return view('tasks.show', compact(
            'task', 'canCreateSubtask', 'assignment', 'statuses', 'canEditTask', 'canAttach', 'attachments',
            'openLog', 'otherOpenLog', 'totalMinutes', 'historyLogs',
        ));
    }

    /* -------------------- создание -------------------- */

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->permissions->check($request->user(), 'tasks.create')) {
            throw new LogicException('Нет права tasks.create');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:task,order,document_task,approval_task,document_prep,document_sign,document_review,service'],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'deadline' => ['nullable', 'date'],
            /* старый формат (одиночный выбор) — совместим со старыми формами и тестами */
            'assignee_type' => ['nullable', 'in:employee,department,multi'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            /* новый формат: нескольким сотрудникам И/ИЛИ нескольким отделам сразу */
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['exists:employees,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
        ], [
            'employee_ids.array' => 'Список исполнителей некорректен.',
            'employee_ids.*.exists' => 'Выбранный сотрудник не найден.',
            'department_ids.array' => 'Список отделов некорректен.',
            'department_ids.*.exists' => 'Выбранный отдел не найден.',
        ]);

        /* нормализация обоих форматов в два плоских списка */
        $employees = array_values(array_unique(array_map('intval', $validated['employee_ids'] ?? [])));
        if (empty($employees) && ! empty($validated['employee_id'])) {
            $employees = [(int) $validated['employee_id']];
        }
        $departments = array_values(array_unique(array_map('intval', $validated['department_ids'] ?? [])));
        if (empty($departments) && ! empty($validated['department_id'])) {
            $departments = [(int) $validated['department_id']];
        }

        $type = $validated['assignee_type'] ?? null;

        // межтенантная изоляция: ID исполнителей/отделов из тела запроса (P0)
        $this->assertEmployeesInCompany($me, $employees, $request->filled('employee_ids') ? 'employee_ids' : 'employee_id');
        $this->assertDepartmentsInCompany($me, $departments, $request->filled('department_ids') ? 'department_ids' : 'department_id');

        if ($type === 'employee' && $employees === []) {
            throw new \InvalidArgumentException('Не выбран сотрудник');
        }
        if ($type === 'department' && $departments === []) {
            throw new \InvalidArgumentException('Не выбран отдел');
        }
        if ($type === 'multi' && count($employees) < 2) {
            throw ValidationException::withMessages(['employee_ids' => 'Выберите минимум двух исполнителей.']);
        }
        if ($type === null && $employees === [] && $departments === []) {
            throw ValidationException::withMessages([
                'employee_ids' => 'Укажите хотя бы одного исполнителя или отдел.',
            ]);
        }

        /* право на назначение задач отделам (tasks.assign_department) */
        if ($departments !== [] && ! $this->permissions->check($request->user(), 'tasks.assign_department')) {
            throw new \InvalidArgumentException('Назначение задач отделу требует права tasks.assign_department');
        }

        $assignees = [];
        foreach ($employees as $employeeId) {
            $assignees[] = ['employee_id' => $employeeId];
        }
        foreach ($departments as $departmentId) {
            $assignees[] = ['department_id' => $departmentId];
        }

        $task = $this->tasks->create($me, array_diff_key($validated, [
            'assignee_type' => null, 'employee_id' => null, 'employee_ids' => null,
            'department_id' => null, 'department_ids' => null,
        ]) + [
            'assignees' => $assignees,
            /* первый отдел — в колонку задачи ( legacy-видимость ), остальные — через task_assignments */
            'department_id' => $departments[0] ?? $me->department_id,
        ]);

        return redirect()->route('tasks.show', $task)->with('status', 'Задача создана: '.$task->number);
    }

    /** Массовое распределение задач (п.47 ТЗ): subtask'и по списку исполнителей от родителя. */
    public function bulkCreate(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:2'],
            'employee_ids.*' => ['exists:employees,id'],
        ]);

        // межтенантная изоляция (P0): подзадачи только сотрудникам своей компании
        $this->assertEmployeesInCompany($me, $validated['employee_ids'], 'employee_ids');

        $created = 0;
        foreach ($validated['employee_ids'] as $employeeId) {
            $this->tasks->createSubtask($me, $task, [
                'title' => $task->title,
                'employee_id' => (int) $employeeId,
            ]);
            $created++;
        }

        return back()->with('status', "Создано подзадач: {$created}");
    }

    /* -------------------- изменение -------------------- */

    /** Редактирование реквизитов задачи: автор или tasks.manage. */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        if ((int) ($task->author_id ?? 0) !== (int) $me->id && $this->permissions->check($request->user(), 'tasks.manage') === false) {
            throw new LogicException('Редактировать задачу может её автор или tasks.manage');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'deadline' => ['nullable', 'date'],
        ]);

        $old = $task->only(['title', 'description', 'priority', 'deadline']);

        $task->update($validated);

        $this->audit->log($request->user(), 'task.updated', 'task', $task->id, $old, $validated);

        return back()->with('status', 'Задача обновлена');
    }

    /** Удаление: автор или tasks.manage; с открытыми подзадачами — запрещено. */
    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        if ((int) ($task->author_id ?? 0) !== (int) $me->id && $this->permissions->check($request->user(), 'tasks.manage') === false) {
            throw new LogicException('Удалить задачу может её автор или tasks.manage');
        }

        $openChildren = $task->children()
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->exists();

        if ($openChildren) {
            return back()->withErrors(['status' => 'Нельзя удалить задачу: есть незакрытые подзадачи']);
        }

        $old = $task->only(['title', 'number', 'status']);

        $task->delete();

        $this->audit->log($request->user(), 'task.deleted', 'task', (int) $task->id, $old, null);

        return redirect()->route('tasks.index')->with('status', 'Задача удалена: '.$task->number);
    }

    public function changeStatus(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        // JSON-клиент (канбан) получает JSON, обычный HTML-форм — редирект как раньше
        $json = $request->expectsJson();

        if (! $this->canSeeTask($request, $task)) {
            if ($json) {
                return response()->json(['message' => 'Нет доступа к этой задаче'], 403);
            }
            abort(403, 'Нет доступа к этой задаче');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:new,planned,in_progress,review,returned,completed,cancelled,overdue'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $status = TaskStatus::from($validated['status']);

        // исполнители задач — роль для сотрудника; автор завершает задачу
        $isAssignee = TaskAssignment::query()
            ->where('task_id', $task->id)
            ->where('employee_id', $me->id)
            ->exists();

        if (! $isAssignee && (int) $task->author_id !== (int) $me->id && $this->permissions->check($request->user(), 'tasks.manage') === false) {
            if ($json) {
                return response()->json(['message' => 'Нет прав менять статус этой задачи'], 403);
            }
            throw new LogicException('Нет прав менять статус этой задачи');
        }

        // завершение задачи (п.42 ТЗ): только автор, tasks.manage или tasks.complete
        if ($status === TaskStatus::Completed
            && (int) $task->author_id !== (int) $me->id
            && ! $this->permissions->check($request->user(), 'tasks.manage')
            && ! $this->permissions->check($request->user(), 'tasks.complete')) {
            $message = 'Завершить задачу может только автор, руководитель (tasks.manage) или holders tasks.complete';
            if ($json) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['status' => $message]);
        }

        $this->tasks->changeStatus($me, $task, $status, $validated['comment'] ?? null);

        if ($json) {
            $task->refresh();

            return response()->json([
                'ok' => true,
                'status' => $task->status->value,
                'message' => 'Статус обновлён: '.$task->status->value,
            ]);
        }

        return back()->with('status', 'Статус обновлён: '.$status->value);
    }

    public function createSubtask(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'deadline' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        // межтенантная изоляция (P0)
        if (($validated['employee_id'] ?? null) !== null) {
            $this->assertEmployeesInCompany($me, [(int) $validated['employee_id']], 'employee_id');
        }

        $subtask = $this->tasks->createSubtask($me, $task, $validated);

        return redirect()->route('tasks.show', $subtask)->with('status', 'Подзадача создана: '.$subtask->number);
    }

    /** Делегирование (п.45 ТЗ). */
    public function delegate(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'assignment_id' => ['required', 'exists:task_assignments,id'],
            'to_employee_id' => ['required', 'exists:employees,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        // межтенантная изоляция (P0): делегирование только внутри своей компании
        $this->assertEmployeesInCompany($me, [(int) $validated['to_employee_id']], 'to_employee_id');

        $assignment = TaskAssignment::query()->findOrFail($validated['assignment_id']);

        // задание обязано принадлежать задаче из маршрута (иначе через свой assignment
        // можно делегировать назначения чужих задач)
        if ((int) $assignment->task_id !== (int) $task->id) {
            abort(404, 'Назначение не найдено в контексте задачи');
        }

        // делегировать может только текущий исполнитель или роутер задач
        if ((int) ($assignment->employee_id ?? 0) !== (int) $me->id
            && $this->permissions->check($request->user(), 'tasks.reassign') === false) {
            throw new LogicException('Делегировать может только текущий исполнитель задания');
        }

        $to = Employee::query()->findOrFail($validated['to_employee_id']);
        $this->tasks->delegate($me, $assignment, $to, $validated['reason'] ?? null);

        return back()->with('status', 'Делегирование выполнено');
    }

    /** Отчёт о выполнении (п.42 ТЗ). */
    public function submitResult(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        $validated = $request->validate([
            'summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->tasks->submitResult($me, $task, $validated['summary'] ?? null);

        return back()->with('status', 'Результат отправлен на проверку');
    }

    /* -------------------- комментарии + время -------------------- */

    public function comment(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        TaskComment::create([
            'task_id' => $task->id,
            'employee_id' => $me->id,
            'body' => $validated['body'],
        ]);

        return back()->with('status', 'Комментарий добавлен');
    }

    /** п.49 ТЗ: учёт затрат времени per task — кнопка старт/стоп на карточке. */
    public function toggleTimeTracking(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        $open = TaskTimeLog::query()
            ->where('employee_id', $me->id)
            ->whereNull('stopped_at')
            ->first();

        if ($open !== null && (int) $open->task_id === (int) $task->id) {
            $this->tasks->stopTimeTracking($me, $task);

            return back()->with('status', 'Учёт времени остановлен');
        }

        $this->tasks->startTimeTracking($me, $task);

        return back()->with('status', 'Учёт времени запущен');
    }

    /* -------------------- файлы -------------------- */

    /** Приложить файл: автор задачи, исполнитель или tasks.manage. */
    public function storeAttachment(Request $request, Task $task): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        if (! $this->canAttachToTask($request, $task, $me)) {
            abort(403, 'Файл к задаче может приложить её автор, исполнитель или tasks.manage');
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', // до 10 МБ
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,ods,jpg,jpeg,png,webp,gif,zip,7z'],
        ]);

        $file = $validated['file'];
        $disk = Storage::disk('local');

        $attachment = TaskAttachment::create([
            'task_id' => $task->id,
            'employee_id' => $me->id,
            'file_path' => $file->store('tasks/'.$task->id, 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ]);

        $this->audit->log($request->user(), 'task.attachment_added', 'task', $task->id, null,
            ['name' => $attachment->original_name, 'size' => $attachment->size]);

        return back()->with('status', 'Файл приложен: '.$attachment->original_name);
    }

    /** Скачивание приложения — доступ по тем же правилам видимости, что и карточка задачи. */
    public function downloadAttachment(Request $request, Task $task, TaskAttachment $attachment): StreamedResponse
    {
        \abort_unless((int) $attachment->task_id === (int) $task->id, 404);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к файлам этой задачи');
        }

        $disk = Storage::disk('local');
        \abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->download($attachment->file_path, $attachment->original_name);
    }

    /** Удалить приложение может его загрузивший, автор задачи или tasks.manage. */
    public function destroyAttachment(Request $request, Task $task, TaskAttachment $attachment): RedirectResponse
    {
        \abort_unless((int) $attachment->task_id === (int) $task->id, 404);

        $me = $request->user()->employee;
        \assert($me !== null);

        if (! $this->canSeeTask($request, $task)) {
            abort(403, 'Нет доступа к этой задаче');
        }

        $allowed = (int) $attachment->employee_id === (int) $me->id
            || (int) ($task->author_id ?? 0) === (int) $me->id
            || $this->permissions->check($request->user(), 'tasks.manage');

        if (! $allowed) {
            abort(403, 'Удалить файл может тот, кто приложил, автор задачи или tasks.manage');
        }

        $old = $attachment->only(['original_name', 'size']);

        Storage::disk('local')->delete($attachment->file_path);
        $attachment->delete();

        $this->audit->log($request->user(), 'task.attachment_removed', 'task', $task->id, $old, null);

        return back()->with('status', 'Файл удалён: '.$old['original_name']);
    }

    /* -------------------- права видимости -------------------- */

    /** Видимость задачи по scope: company — все, department — свой отдел/автор/исполнитель, own — автор/исполнитель. */
    protected function canSeeTask(Request $request, Task $task): bool
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'tasks.view');

        return match (true) {
            // company-scope = «вся моя компания», а не «все компании» (межтенантная изоляция)
            $scope === PermissionScope::Company => (int) $task->company_id === (int) $me->company_id,
            $scope === PermissionScope::Department,
            $scope === PermissionScope::Own => $this->isTaskActor($task, $me)
                || ($scope === PermissionScope::Department && (int) $task->department_id === (int) $me->department_id),
            default => false,
        };
    }

    /** Автор, исполнитель или назначение через отдел. */
    protected function isTaskActor(Task $task, Employee $me): bool
    {
        if ((int) ($task->author_id ?? 0) === (int) $me->id) {
            return true;
        }

        return $this->assignedToMe($me)->where('task_id', $task->id)->exists();
    }

    /**
     * Назначения, где задача моя: назначена лично мне или моему отделу
     * (одна задача может быть назначена нескольким сотрудникам и отделам).
     */
    protected function assignedToMe(Employee $me): Builder
    {
        return TaskAssignment::query()->where(function ($q) use ($me) {
            $q->where('employee_id', $me->id);
            if ($me->department_id !== null) {
                $q->orWhere('department_id', $me->department_id);
            }
        });
    }

    protected function canAttachToTask(Request $request, Task $task, Employee $me): bool
    {
        return $this->isTaskActor($task, $me) || $this->permissions->check($request->user(), 'tasks.manage');
    }

    /**
     * Межтенантная изоляция ссылок из тела запроса (P0): middleware видит только
     * параметры маршрута, поэтому employee_id(s)/department_id(s) из формы
     * проверяются здесь — назначение на чужую компанию запрещено (422).
     *
     * @param  array<int, int>  $ids
     */
    protected function assertEmployeesInCompany(Employee $me, array $ids, string $field): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $found = Employee::query()
            ->where('company_id', $me->company_id)
            ->whereKey($ids)
            ->count();

        if ($found !== count($ids)) {
            throw ValidationException::withMessages([$field => 'Выбранный сотрудник не найден.']);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    protected function assertDepartmentsInCompany(Employee $me, array $ids, string $field): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $found = Department::query()
            ->where('company_id', $me->company_id)
            ->whereKey($ids)
            ->count();

        if ($found !== count($ids)) {
            throw ValidationException::withMessages([$field => 'Выбранный отдел не найден.']);
        }
    }
}
