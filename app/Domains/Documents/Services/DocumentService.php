<?php

declare(strict_types=1);

namespace App\Domains\Documents\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Workflow\WorkflowEngine;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkflow;
use App\Models\DocumentWorkflowState;
use App\Models\DocumentWorkflowStep;
use App\Models\Employee;
use App\Models\Role;
use App\Support\NumberGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Документооборот (п.31–37 ТЗ): создание, версии с sha256, шаблоны (Blade
 * с переменными), маршруты согласования (workflow), регистрация, архив.
 */
class DocumentService
{
    public function __construct(
        protected AuditService $audit,
        protected WorkflowEngine $engine,
        protected NumberGenerator $numbers,
    ) {}

    /* -------------------- создание -------------------- */

    public function create(Employee $author, array $data, ?UploadedFile $file = null): Document
    {
        return DB::transaction(function () use ($author, $data, $file) {
            $document = Document::create([
                'company_id' => $author->company_id,
                'document_number' => $this->nextNumber(),
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'type' => $data['type'] ?? 'internal',
                'priority' => $data['priority'] ?? 'medium',
                'confidentiality' => $data['confidentiality'] ?? 'internal',
                'due_at' => $data['due_at'] ?? null,
                'author_id' => $author->id,
                'department_id' => $data['department_id'] ?? $author->department_id,
                'template_id' => $data['template_id'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'status' => 'draft',
            ]);

            if ($file !== null || isset($data['body'])) {
                $this->addVersion($document, $author, $file, bodyContent: $data['body'] ?? null);
            }

            $this->audit->log($author->user, 'document.created', 'document', $document->id, null, [
                'document_number' => $document->document_number,
                'title' => $document->title,
            ]);

            return $document;
        });
    }

    /** Создание документа из шаблона c подстановкой переменных (п.34 ТЗ). */
    public function createFromTemplate(Employee $author, DocumentTemplate $template, array $variables, array $overrides = []): Document
    {
        $rendered = $this->renderTemplate($template, $variables, $author, $overrides);

        $document = Document::create([
            'company_id' => $author->company_id,
            'document_number' => $this->nextNumber(),
            'category_id' => $template->category_id,
            'title' => $overrides['title'] ?? $template->name,
            'body' => $rendered,
            'type' => $overrides['type'] ?? 'internal',
            'priority' => $overrides['priority'] ?? 'medium',
            'confidentiality' => $overrides['confidentiality'] ?? 'internal',
            'due_at' => $overrides['due_at'] ?? null,
            'author_id' => $author->id,
            'department_id' => $overrides['department_id'] ?? $author->department_id,
            'template_id' => $template->id,
            'task_id' => $overrides['task_id'] ?? null,
            'status' => 'draft',
        ]);

        $this->addVersion($document, $author, null, bodyContent: $rendered, changeNote: __('Сгенерировано из шаблона «').$template->name.'»');

        $this->audit->log($author->user, 'document.created_from_template', 'document', $document->id, null, [
            'template_id' => $template->id,
            'document_number' => $document->document_number,
        ]);

        return $document;
    }

    /**
     * Рендер шаблона: {{ $var }} подстановкой (безопасно, без eval).
     * Разрешены только объявленные в variables переменные + стандартный контекст.
     */
    public function renderTemplate(DocumentTemplate $template, array $variables, Employee $author, array $overrides = []): string
    {
        $declared = $template->variables ?? [];

        foreach ($declared as $name) {
            if (! array_key_exists($name, $variables)) {
                throw new LogicException("Переменная «{$name}» обязательна для шаблона «{$template->name}»");
            }
        }

        $body = $template->body;

        // стандартные переменные контекста
        $context = [
            'today_date' => Carbon::now()->format('d.m.Y'),
            'today_full_date' => Carbon::now()->translatedFormat('j F Y'),
            'employee_name' => $author->last_name.' '.$author->first_name,
            'employee_no' => $author->employee_no,
            'company_name' => $author->company?->name ?? '',
            'deadline' => isset($overrides['due_at']) ? Carbon::parse($overrides['due_at'])->format('d.m.Y') : '',
        ];

        foreach ($variables as $key => $value) {
            $context[$key] = (string) $value;
        }

        foreach ($context as $key => $value) {
            $body = str_replace('{{ $'.$key.' }}', $value, $body);
        }

        return $body;
    }

    /* -------------------- версии -------------------- */

    public function addVersion(Document $document, Employee $actor, ?UploadedFile $file, ?string $bodyContent = null, ?string $changeNote = null): DocumentVersion
    {
        return DB::transaction(function () use ($document, $actor, $file, $bodyContent, $changeNote) {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);

            // SEC-04: подписанная версия неизменяема; поверх неё нельзя ни править body,
            // ни создать следующую версию до перевода документа в новую жизнь.
            if ($locked->currentVersion?->immutable === true
                || in_array($locked->status->value, ['signed', 'registered', 'archived'], true)) {
                throw new LogicException(__('Подписанная версия документа неизменяема'));
            }

            $nextVersion = (int) $locked->versions()->max('version') + 1;

            if ($file !== null) {
                $path = $file->store('documents/'.$document->id, 'local');
                $hash = hash('sha256', $file->getContent());
                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version' => $nextVersion,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'sha256' => $hash,
                    'change_note' => $changeNote,
                    'created_by' => $actor->id,
                ]);
            } else {
                $hash = hash('sha256', (string) $bodyContent);
                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version' => $nextVersion,
                    'file_path' => null,
                    'file_name' => null,
                    'mime_type' => null,
                    'size' => mb_strlen((string) $bodyContent, '8bit'),
                    'sha256' => $hash,
                    'change_note' => $changeNote,
                    'created_by' => $actor->id,
                ]);
            }

            $locked->update(['current_version_id' => $version->id]);
            // Держим переданную модель в синхроне: create() сразу возвращает документ
            // с актуальной текущей версией.
            $document->forceFill(['current_version_id' => $version->id]);

            $this->audit->log($actor->user, 'document.version_added', 'document_version', $version->id, null, [
                'document_id' => $document->id,
                'version' => $nextVersion,
                'sha256' => $hash,
            ]);

            return $version;
        });
    }

    /* -------------------- workflow согласование (п.32, 35, 36) -------------------- */

    /** Отправка на согласование: первый шаг + pending-approval. */
    public function submit(Employee $actor, Document $document, ?DocumentWorkflow $workflow = null): Document
    {
        $from = $document->status->value;
        $this->engine->assert(
            WorkflowEngine::SUBJECT_DOCUMENT,
            $from,
            'submitted',
            'Документ в статусе '.$from.' нельзя отправить на согласование',
        );

        return DB::transaction(function () use ($actor, $document, $workflow, $from) {
            $workflow ??= $this->pickWorkflow($document);

            if ($workflow === null) {
                throw new LogicException(__('Для категории документа нет активного workflow'));
            }

            DocumentWorkflowState::query()->updateOrCreate(
                ['document_id' => $document->id],
                [
                    'workflow_id' => $workflow->id,
                    'current_step' => 1,
                    'state' => 'in_progress',
                ]
            );

            $step = $this->step($workflow, 1);
            $this->createApproval($document, $step);

            $document->update(['status' => 'submitted']);
            $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'submitted', 'submit', $actor->user);

            $this->audit->log($actor->user, 'document.submitted', 'document', $document->id, null, [
                'workflow_id' => $workflow->id,
                'step' => $step->name,
            ]);

            return $document;
        });
    }

    public function approve(Employee $approver, Document $document, ?string $comment = null): Document
    {
        return $this->decide($approver, $document, 'approved', $comment);
    }

    public function reject(Employee $approver, Document $document, string $comment): Document
    {
        return $this->decide($approver, $document, 'rejected', $comment);
    }

    public function decide(Employee $approver, Document $document, string $decision, ?string $comment): Document
    {
        return DB::transaction(function () use ($approver, $document, $decision, $comment) {
            // H-BL-05: блокируем документ и текущее согласование внутри транзакции —
            // параллельные approve/reject не должны дважды сдвинуть workflow.
            $lockedDocument = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $from = $lockedDocument->status->value;
            $pivot = DocumentWorkflowState::query()->where('document_id', $document->id)
                ->lockForUpdate()
                ->firstOrFail();
            $workflow = DocumentWorkflow::query()->findOrFail($pivot->workflow_id);
            $step = $this->step($workflow, $pivot->current_step);

            $approval = DocumentApproval::query()->where('document_id', $document->id)
                ->where('workflow_step_id', $step->id)
                ->where('status', 'pending')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($approval === null) {
                throw new LogicException(__('Нет активного согласования на текущем шаге'));
            }

            if ($this->isActorOfStep($approver, $step, $document) === false) {
                throw new LogicException(__('Текущий шаг должен согласовать другой уполномоченный'));
            }

            $approval->update([
                'approver_id' => $approver->id,
                'status' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            if ($decision === 'rejected') {
                // документ на доработку: автору ждём новой версии и повторной отправки
                $this->engine->assert(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'revision', 'Решение «отклонить» недоступно для статуса '.$from);
                $document->update(['status' => 'revision']);
                $pivot->update(['state' => 'failed']);
                $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'revision', 'reject', $approver->user, $comment);
                $this->audit->log($approver->user, 'document.rejected', 'document', $document->id, null, ['comment' => $comment]);

                return $document;
            }

            if ($step->is_terminal || $step->step_order >= $workflow->steps()->max('step_order')) {
                $this->engine->assert(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'approved', 'Согласовать документ в статусе '.$from.' нельзя');
                $pivot->update(['state' => 'completed']);
                $document->update(['status' => 'approved']);
                $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'approved', 'approve_final', $approver->user, $comment);
                $this->audit->log($approver->user, 'document.approved_final', 'document', $document->id, null, ['comment' => $comment]);

                return $document;
            }

            $this->engine->assert(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'review', 'Согласовать документ в статусе '.$from.' нельзя');
            $nextStep = $this->step($workflow, $pivot->current_step + 1);
            $pivot->increment('current_step');
            $this->createApproval($document, $nextStep);

            $document->update(['status' => 'review']);
            $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'review', 'approve_step', $approver->user, $comment);
            $this->audit->log($approver->user, 'document.approved_step', 'document', $document->id, null, [
                'step' => $nextStep->name,
                'comment' => $comment,
            ]);

            return $document;
        });
    }

    /** Регистрация после полного согласования (п.35 ТЗ). */
    public function register(Employee $registrar, Document $document): Document
    {
        // DB-07: статус, номер, workflow и аудит фиксируются одной транзакцией;
        // блокировка строки документа не даёт зарегистрировать его дважды.
        return DB::transaction(function () use ($registrar, $document) {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            $from = $document->status->value;
            $this->engine->assert(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'registered', __('Регистрировать можно только согласованный документ'));

            $document->update([
                'status' => 'registered',
                'reg_number' => $this->nextRegNumber($document->company_id),
            ]);
            $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'registered', 'register', $registrar->user, $document->reg_number);

            $this->audit->log($registrar->user, 'document.registered', 'document', $document->id, null, ['reg_number' => $document->reg_number]);

            return $document;
        });
    }

    public function archive(Employee $actor, Document $document): Document
    {
        $from = $document->status->value;
        $this->engine->assert(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'archived', 'Архивировать можно только зарегистрированный/подписанный документ');

        $document->update(['status' => 'archived']);
        $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'archived', 'archive', $actor->user, null);
        $this->audit->log($actor->user, 'document.archived', 'document', $document->id, null, null);

        return $document;
    }

    /* -------------------- внутренние -------------------- */

    protected function pickWorkflow(Document $document): ?DocumentWorkflow
    {
        $workflows = DocumentWorkflow::query()->where('company_id', $document->company_id)->where('is_active', true)->get();

        foreach ($workflows as $workflow) {
            $applicable = $workflow->applicable_categories;

            if ($applicable === null || in_array($document->category->code, (array) $applicable, true)) {
                return $workflow;
            }
        }

        return null;
    }

    protected function step(DocumentWorkflow $workflow, int $order): DocumentWorkflowStep
    {
        $step = DocumentWorkflowStep::query()->where('workflow_id', $workflow->id)->where('step_order', $order)->first();

        if ($step === null) {
            throw new LogicException('Шаг '.$order.' workflow не найден');
        }

        return $step;
    }

    protected function createApproval(Document $document, DocumentWorkflowStep $step): DocumentApproval
    {
        return DocumentApproval::create([
            'document_id' => $document->id,
            'workflow_step_id' => $step->id,
            'status' => 'pending',
        ]);
    }

    /** Может ли $approver согласовать текущий шаг? */
    public function isActorOfStep(Employee $approver, DocumentWorkflowStep $step, Document $document): bool
    {
        $actors = $this->actorsOfStep($step, $document);

        return $actors->contains(fn ($a) => $a->id === $approver->id);
    }

    public function actorsOfStep(DocumentWorkflowStep $step, Document $document): Collection
    {
        return match ($step->actor_type->value) {
            'author' => $document->author !== null ? collect([$document->author]) : collect(),
            // BL-21: «employee» — конкретный сотрудник шага, никогда автор документа.
            // Без назначенного сотрудника шаг остаётся недоступным (fail-closed).
            'employee' => $step->employee_id !== null
                ? Employee::query()
                    ->whereKey((int) $step->employee_id)
                    ->where('company_id', $document->company_id)
                    ->where('status', 'active')
                    ->get()
                : collect(),
            'ceo' => $this->employeesByRoleSlug($document->company_id, 'company_director'),
            'role' => $this->employeesByRoleId($document->company_id, (int) $step->role_id),
            'department' => Employee::query()
                ->where('department_id', $step->department_id ?? $document->department_id)
                ->where('status', 'active')
                ->get(),
            default => collect(),
        };
    }

    protected function employeesByRoleSlug(int $companyId, string $slug): Collection
    {
        $role = Role::query()->where('slug', $slug)->value('id');

        return $role === null ? collect() : $this->employeesByRoleId($companyId, (int) $role);
    }

    protected function employeesByRoleId(int $companyId, int $roleId): Collection
    {
        return Employee::query()
            ->join('employee_roles', 'employee_roles.employee_id', '=', 'employees.id')
            ->where('employee_roles.role_id', $roleId)
            ->where('employees.company_id', $companyId)
            ->where('employees.status', 'active')
            ->get(['employees.*']);
    }

    /**
     * Внутренний номер Д-ГГГГ-NNNNNN.
     *
     * unique-индекс на documents.document_number глобальный (вне компании), поэтому
     * счётчик считается по всей таблице, но с учётом soft-deleted записей: иначе
     * после удаления документа следующий номер совпал бы с существующим и создание
     * упало бы на unique-ограничении. Цикл защиты от коллизии берёт первый свободный
     * номер (гонка/легаси-записи с пропусками).
     */
    protected function nextNumber(): string
    {
        $year = now()->format('Y');
        $prefix = 'Д-'.$year.'-';

        // BL-07/DB-08: стартовое значение берём из максимального суффикса
        // существующих номеров (включая soft-deleted), чтобы не пересечься
        // с легаси-пропусками. Дальше номер выдаёт атомарная последовательность.
        $value = $this->numbers->next('document.number', $year, fn () => $this->numbers->maxSuffix(
            $prefix,
            Document::query()->withTrashed()->where('document_number', 'like', $prefix.'%')->pluck('document_number'),
        ));

        return sprintf('%s%06d', $prefix, $value);
    }

    /**
     * Регистрационный номер Р-ГГГГ-NNNNNN в пределах компании.
     *
     * Как и nextNumber(): счётчик с soft-deleted записями + защита от коллизии,
     * иначе уникальный reg_number падает после удаления документа.
     */
    protected function nextRegNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $prefix = 'Р-'.$year.'-';

        $value = $this->numbers->next('document.reg_number.'.$companyId, $year, fn () => $this->numbers->maxSuffix(
            $prefix,
            Document::query()->withTrashed()
                ->where('company_id', $companyId)
                ->where('reg_number', 'like', $prefix.'%')
                ->pluck('reg_number'),
        ));

        return sprintf('%s%06d', $prefix, $value);
    }
}
