<?php

declare(strict_types=1);

namespace App\Domains\Workflow;

use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Единый движок согласований (Этап B: Workflow Engine).
 *
 * Детерминированная статусная машина поверх существующих enum-контрактов
 * (DocumentStatus, LeaveStatus, PayrollPeriodStatus). Правила переходов —
 * декларативная таблица TRANSITIONS; нарушение → LogicException, который
 * контроллеры превращают в back()->withErrors(['field' => ...]).
 *
 * Каждый успешный переход журналируется в workflow_transitions — единая
 * история для таймлайнов (документы / отпуска / ведомости).
 */
final class WorkflowEngine
{
    public const SUBJECT_DOCUMENT = 'document';

    public const SUBJECT_LEAVE = 'leave_request';

    public const SUBJECT_PAYROLL = 'payroll_period';

    /**
     * subject => from_status => список разрешённых to_status.
     * Self-переходы (review→review, calculated→calculated, signing→signing)
     * допускаются только там, где это штатное поведение (следующий шаг /
     * повторный расчёт / повторный запрос подписей).
     *
     * @var array<string, array<string, list<string>>>
     */
    private const TRANSITIONS = [
        // Документы: draft/revision → submitted → (review ↔) approved → registered → signed → archived
        self::SUBJECT_DOCUMENT => [
            'draft' => ['submitted'],
            'revision' => ['submitted'],
            'submitted' => ['review', 'approved', 'revision'],
            'review' => ['review', 'approved', 'revision'],
            'approved' => ['registered', 'signing'],
            'registered' => ['archived', 'signing'],
            'signing' => ['signing', 'signed', 'revision'],
            'signed' => ['archived'],
            'archived' => [],
            'rejected' => [],
        ],

        // Отпуска: решение принимается только из pending (единственный шаг).
        self::SUBJECT_LEAVE => [
            'pending' => ['approved', 'rejected'],
            'approved' => [],
            'rejected' => [],
            'cancelled' => [],
        ],

        // Зарплата: draft → calculated ⇄ calculated (пересчёт) → approved → paid.
        self::SUBJECT_PAYROLL => [
            'draft' => ['calculated'],
            'calculated' => ['calculated', 'approved'],
            'approved' => ['paid'],
            'paid' => [],
        ],
    ];

    /** Допустим ли переход (чистая функция, без состояния и без запросов). */
    public function can(string $subject, string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$subject][$from] ?? [], true);
    }

    /**
     * Жёсткая проверка перехода. Нарушение — LogicException.
     * $message — пользовательская копия ошибки (иначе — служебное сообщение).
     */
    public function assert(string $subject, string $from, string $to, ?string $message = null): void
    {
        if ($this->can($subject, $from, $to)) {
            return;
        }

        throw new LogicException($message ?? sprintf('Переход не разрешён: %s → %s (%s)', $from, $to, $subject));
    }

    /**
     * Явно разрешённый системный переход «быстрая подпись по QR».
     *
     * Обычная машина состояний не допускает draft/revision → signing: документ
     * сначала должен пройти согласование. QR-подпись — единственное исключение,
     * и оно должно вызывать assert до record, чтобы история не содержала
     * невалидных переходов.
     */
    public function assertQuickQrSignature(string $from): void
    {
        if (! in_array($from, ['draft', 'revision', 'approved', 'signing'], true)) {
            throw new LogicException(sprintf('Быстрая QR-подпись недоступна из статуса %s', $from));
        }
    }

    /** Журнализация успешного перехода (вызывать после apply внутри транзакции). */
    public function record(
        string $subject,
        int $subjectId,
        ?string $from,
        string $to,
        string $action,
        ?User $actor = null,
        ?string $comment = null,
    ): WorkflowTransition {
        return WorkflowTransition::create([
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'actor_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }

    /** История переходов субъекта в хронологическом порядке. */
    public function history(string $subject, int $subjectId): Collection
    {
        return WorkflowTransition::query()
            ->where('subject_type', $subject)
            ->where('subject_id', $subjectId)
            ->with('actor')
            ->orderBy('id')
            ->get();
    }
}
