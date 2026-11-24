<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Documents\Services\DocumentService;
use App\Domains\Lms\Services\LmsService;
use App\Domains\Tasks\Services\TaskService;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LmsCourse;
use App\Models\OnboardingAccessGrant;
use App\Models\OnboardingFlow;
use App\Models\OnboardingStep;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\EmployeeStatus;
use App\Shared\Enums\OnboardingFlowStatus;
use App\Shared\Enums\OnboardingStepStatus;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ONB-01: оркестратор найма.
 *
 * Один поток на сотрудника. Каждый шаг имеет свой след (документ, запись на
 * курс, задача, грант роли), поэтому повтор события не дублирует побочные
 * эффекты. Отказ шага фиксируется и не откатывает уже сделанные шаги — retry
 * начинается с этого шага. При отзыве снимаются только роли, выданные потоком.
 */
class OnboardingOrchestrator
{
    /** Порядок обязателен: от профиля к доступам. */
    public const STEPS = ['profile', 'documents', 'training', 'tasks', 'access'];

    public function __construct(
        protected AuditService $audit,
        protected DocumentService $documents,
        protected LmsService $lms,
        protected TaskService $tasks,
        protected PermissionService $permissions,
    ) {}

    /**
     * Создать поток ровно один раз. Повторный вызов возвращает тот же поток
     * и не перезаписывает выбранные курсы/роли.
     *
     * @param  array<string,mixed>  $options
     */
    public function start(Employee $employee, User $actor, array $options = []): OnboardingFlow
    {
        $author = $this->actorEmployee($actor);
        TenantGuard::ensureEmployee($employee, $author);

        $payload = $this->normalizeOptions($options);

        $flow = DB::transaction(function () use ($employee, $actor, $author, $payload): OnboardingFlow {
            $flow = OnboardingFlow::query()->firstOrCreate([
                'employee_id' => $employee->id,
            ], [
                'company_id' => $author->company_id,
                'status' => OnboardingFlowStatus::Pending,
                'payload' => $payload,
                'created_by' => $actor->id,
            ]);

            TenantGuard::ensure($flow, $author);

            foreach (self::STEPS as $key) {
                OnboardingStep::query()->firstOrCreate([
                    'flow_id' => $flow->id,
                    'key' => $key,
                ], [
                    'company_id' => $flow->company_id,
                    'status' => OnboardingStepStatus::Pending,
                ]);
            }

            if ($flow->wasRecentlyCreated) {
                $this->audit->log($actor, 'onboarding.started', 'onboarding_flow', $flow->id, null, [
                    'employee_id' => $employee->id,
                    'steps' => self::STEPS,
                ]);
            }

            return $flow;
        });

        return $flow->load('steps');
    }

    /** Выполнить все незавершённые шаги по порядку. */
    public function run(OnboardingFlow $flow, User $actor): OnboardingFlow
    {
        $author = $this->actorEmployee($actor);
        TenantGuard::ensure($flow, $author);
        TenantGuard::ensureEmployee($flow->employee()->firstOrFail(), $author);

        if ($flow->status === OnboardingFlowStatus::Cancelled) {
            throw new InvalidArgumentException('Поток онбординга отменён');
        }

        if ($flow->status === OnboardingFlowStatus::Completed) {
            return $flow->load('steps');
        }

        if ($flow->status !== OnboardingFlowStatus::InProgress) {
            $flow->status = OnboardingFlowStatus::InProgress;

            if ($flow->started_at === null) {
                $flow->started_at = now();
            }

            $flow->save();
        }

        $failed = false;

        foreach ($flow->steps()->orderBy('id')->get() as $step) {
            if ($step->status === OnboardingStepStatus::Completed) {
                continue;
            }

            $claimed = OnboardingStep::query()
                ->whereKey($step->id)
                ->where('status', '!=', OnboardingStepStatus::Completed->value)
                ->increment('attempts');

            if ($claimed === 0) {
                continue;
            }

            try {
                $result = $this->runStep($flow, $step->key, $actor, $author);
                $step->update([
                    'status' => OnboardingStepStatus::Completed,
                    'payload' => $result,
                    'error' => null,
                ]);
                $this->audit->log($actor, 'onboarding.step_completed', 'onboarding_flow', $flow->id, null, [
                    'step' => $step->key,
                ]);
            } catch (Throwable $e) {
                $failed = true;
                $step->update([
                    'status' => OnboardingStepStatus::Failed,
                    'error' => mb_substr($e->getMessage(), 0, 1000),
                ]);
                $flow->status = OnboardingFlowStatus::Failed;
                $flow->save();
                $this->audit->log($actor, 'onboarding.step_failed', 'onboarding_flow', $flow->id, null, [
                    'step' => $step->key,
                    'error' => mb_substr($e->getMessage(), 0, 255),
                ]);
                break;
            }
        }

        if (! $failed) {
            $remaining = $flow->steps()
                ->where('status', '!=', OnboardingStepStatus::Completed->value)
                ->exists();

            if (! $remaining) {
                $flow->status = OnboardingFlowStatus::Completed;
                $flow->completed_at = now();
                $flow->save();
                $this->audit->log($actor, 'onboarding.completed', 'onboarding_flow', $flow->id, null, [
                    'employee_id' => $flow->employee_id,
                ]);
            }
        }

        return $flow->fresh()->load('steps');
    }

    /**
     * Сбросить конкретный шаг и продолжить поток.
     *
     * @param  array<string,mixed>  $options  исправленные курсы/роли/заголовок задачи
     */
    public function retry(OnboardingFlow $flow, string $key, User $actor, array $options = []): OnboardingFlow
    {
        if (! in_array($key, self::STEPS, true)) {
            throw new InvalidArgumentException('Неизвестный шаг онбординга');
        }

        $author = $this->actorEmployee($actor);
        TenantGuard::ensure($flow, $author);

        if ($options !== []) {
            $patch = [];

            if (array_key_exists('course_ids', $options)) {
                $patch['course_ids'] = array_values(array_unique(array_map('intval', $options['course_ids'])));
            }

            if (array_key_exists('role_slugs', $options)) {
                $patch['role_slugs'] = array_values(array_unique(array_map('strval', $options['role_slugs'])));
            }

            if (array_key_exists('task_title', $options)) {
                $patch['task_title'] = trim((string) $options['task_title']);
            }

            $flow->payload = array_replace($flow->payload ?? [], $patch);
            $flow->save();
        }

        $step = $flow->steps()->where('key', $key)->firstOrFail();
        $step->update([
            'status' => OnboardingStepStatus::Pending,
            'error' => null,
        ]);

        if ($flow->status !== OnboardingFlowStatus::Cancelled) {
            $flow->status = OnboardingFlowStatus::Failed;
            $flow->completed_at = null;
            $flow->save();
        }

        $this->audit->log($actor, 'onboarding.retried', 'onboarding_flow', $flow->id, null, ['step' => $key]);

        return $this->run($flow, $actor);
    }

    /** Отозвать только роли, выданные этим потоком. */
    public function revoke(OnboardingFlow $flow, User $actor): OnboardingFlow
    {
        $author = $this->actorEmployee($actor);
        TenantGuard::ensure($flow, $author);

        DB::transaction(function () use ($flow, $actor, $author): void {
            $locked = OnboardingFlow::query()->whereKey($flow->id)->lockForUpdate()->firstOrFail();
            TenantGuard::ensure($locked, $author);

            if ($locked->status === OnboardingFlowStatus::Cancelled) {
                return;
            }

            foreach ($locked->grants()->whereNull('revoked_at')->get() as $grant) {
                if (! $grant->pre_existing) {
                    EmployeeRole::query()
                        ->where('employee_id', $grant->employee_id)
                        ->where('role_id', $grant->role_id)
                        ->delete();
                }

                $grant->revoked_at = now();
                $grant->save();
            }

            $locked->status = OnboardingFlowStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();

            $this->audit->log($actor, 'onboarding.access_revoked', 'onboarding_flow', $locked->id, null, [
                'employee_id' => $locked->employee_id,
            ]);
        });

        $this->permissions->forget();

        return $flow->fresh()->load('steps', 'grants');
    }

    /** @return array<string,mixed> */
    private function runStep(OnboardingFlow $flow, string $key, User $actor, Employee $author): array
    {
        $employee = $flow->employee()->firstOrFail();

        return match ($key) {
            'profile' => $this->stepProfile($employee),
            'documents' => $this->stepDocuments($flow, $employee, $author),
            'training' => $this->stepTraining($flow, $employee, $actor),
            'tasks' => $this->stepTasks($flow, $employee, $author),
            'access' => $this->stepAccess($flow, $employee, $actor, $author),
            default => throw new InvalidArgumentException('Неизвестный шаг онбординга: '.$key),
        };
    }

    /** @return array<string,mixed> */
    private function stepProfile(Employee $employee): array
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new RuntimeException('Сотрудник не активен');
        }

        foreach (['first_name', 'last_name', 'employee_no'] as $field) {
            if (trim((string) $employee->{$field}) === '') {
                throw new RuntimeException('Профиль сотрудника заполнен не полностью: '.$field);
            }
        }

        return ['employee_id' => $employee->id, 'employee_no' => $employee->employee_no];
    }

    /** @return array<string,mixed> */
    private function stepDocuments(OnboardingFlow $flow, Employee $employee, Employee $author): array
    {
        $title = 'Онбординг: '.$employee->first_name.' '.$employee->last_name.' ('.$employee->employee_no.')';
        $document = Document::query()
            ->where('company_id', $flow->company_id)
            ->where('title', $title)
            ->first();

        if ($document === null) {
            $category = DocumentCategory::query()
                ->where('code', config('corpms.onboarding.document_category_code', 'onboarding'))
                ->where('is_active', true)
                ->first()
                ?? DocumentCategory::query()->where('is_active', true)->first();

            if ($category === null) {
                throw new RuntimeException('Нет активной категории документов для онбординга');
            }

            $document = $this->documents->create($author, [
                'category_id' => $category->id,
                'title' => $title,
                'body' => __('Служебный документ автоматического онбординга сотрудника ').$employee->employee_no,
                'type' => 'internal',
            ]);
        }

        return ['document_id' => $document->id, 'title' => $title];
    }

    /** @return array<string,mixed> */
    private function stepTraining(OnboardingFlow $flow, Employee $employee, User $actor): array
    {
        $courseIds = array_values(array_unique(array_map('intval', $flow->payload['course_ids'] ?? [])));

        if ($courseIds === []) {
            return ['course_ids' => [], 'enrollment_ids' => []];
        }

        $courses = LmsCourse::query()
            ->where('company_id', $flow->company_id)
            ->whereKey($courseIds)
            ->get();

        if ($courses->count() !== count($courseIds)) {
            throw new RuntimeException('Один из курсов онбординга недоступен в компании');
        }

        $enrollmentIds = [];
        foreach ($courses as $course) {
            $enrollmentIds[] = $this->lms->enroll($course, $employee, $actor)->id;
        }

        return ['course_ids' => $courseIds, 'enrollment_ids' => $enrollmentIds];
    }

    /** @return array<string,mixed> */
    private function stepTasks(OnboardingFlow $flow, Employee $employee, Employee $author): array
    {
        $base = trim((string) ($flow->payload['task_title'] ?? config('corpms.onboarding.task_title', '')));

        if ($base === '') {
            throw new RuntimeException('Не задан заголовок задачи онбординга');
        }

        $title = 'Онбординг #'.$flow->id.': '.$base;
        $task = Task::query()
            ->where('company_id', $flow->company_id)
            ->where('title', $title)
            ->first();

        if ($task === null) {
            $task = $this->tasks->create($author, [
                'title' => $title,
                'description' => __('Автоматическая задача адаптации нового сотрудника.'),
                'assignees' => [['employee_id' => $employee->id]],
            ]);
        }

        return ['task_id' => $task->id, 'title' => $title];
    }

    /** @return array<string,mixed> */
    private function stepAccess(OnboardingFlow $flow, Employee $employee, User $actor, Employee $author): array
    {
        $slugs = array_values(array_unique(array_map('strval', $flow->payload['role_slugs'] ?? ['employee'])));

        if ($slugs === []) {
            throw new RuntimeException('Не заданы роли онбординга');
        }

        $roles = Role::query()->whereIn('slug', $slugs)->get()->keyBy('slug');
        $missing = array_values(array_diff($slugs, $roles->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException('Роль онбординга не найдена: '.implode(', ', $missing));
        }

        $isSuper = $this->permissions->roleNames($actor)->contains('super_admin');
        $actorRoleIds = EmployeeRole::query()
            ->where('employee_id', $author->id)
            ->pluck('role_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $roleIds = [];

        foreach ($slugs as $slug) {
            $role = $roles->get($slug);
            \assert($role instanceof Role);
            $roleId = (int) $role->id;

            if (! $isSuper && ! in_array($roleId, $actorRoleIds, true)) {
                throw new RuntimeException('Недостаточно прав для выдачи роли: '.$slug);
            }

            $preExisting = EmployeeRole::query()
                ->where('employee_id', $employee->id)
                ->where('role_id', $roleId)
                ->exists();

            $grant = OnboardingAccessGrant::query()->firstOrCreate([
                'flow_id' => $flow->id,
                'role_id' => $roleId,
            ], [
                'company_id' => $flow->company_id,
                'employee_id' => $employee->id,
                'pre_existing' => $preExisting,
                'granted_at' => now(),
            ]);

            if (! $preExisting && ! $grant->pre_existing) {
                EmployeeRole::query()->firstOrCreate([
                    'employee_id' => $employee->id,
                    'role_id' => $roleId,
                ]);
            }

            $roleIds[] = $roleId;
        }

        $this->permissions->forget();

        return ['role_slugs' => $slugs, 'role_ids' => $roleIds];
    }

    /** @param array<string,mixed> $options
     * @return array{course_ids:list<int>,role_slugs:list<string>,task_title:string}
     */
    private function normalizeOptions(array $options): array
    {
        return [
            'course_ids' => array_values(array_unique(array_map('intval', $options['course_ids'] ?? []))),
            'role_slugs' => array_values(array_unique(array_map('strval', $options['role_slugs'] ?? config('corpms.onboarding.roles', ['employee'])))),
            'task_title' => trim((string) ($options['task_title'] ?? config('corpms.onboarding.task_title', ''))),
        ];
    }

    private function actorEmployee(User $actor): Employee
    {
        $employee = $actor->employee;

        if ($employee === null) {
            throw new RuntimeException('У пользователя нет сотрудника компании');
        }

        return $employee;
    }
}
