<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Domains\Workflow\WorkflowEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Этап B: единый движок согласований — декларативные правила переходов,
 * жёсткая проверка (LogicException) и журнал workflow_transitions.
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(WorkflowEngine::class);
    }

    #[Test]
    public function document_transition_rules(): void
    {
        // штатный жизненный цикл
        $this->assertTrue($this->engine->can('document', 'draft', 'submitted'));
        $this->assertTrue($this->engine->can('document', 'revision', 'submitted'));
        $this->assertTrue($this->engine->can('document', 'submitted', 'review'));
        $this->assertTrue($this->engine->can('document', 'submitted', 'approved'));
        $this->assertTrue($this->engine->can('document', 'review', 'review')); // следующий шаг
        $this->assertTrue($this->engine->can('document', 'submitted', 'revision'));
        $this->assertTrue($this->engine->can('document', 'approved', 'registered'));
        $this->assertTrue($this->engine->can('document', 'approved', 'signing'));
        $this->assertTrue($this->engine->can('document', 'signing', 'signing')); // повторный запрос
        $this->assertTrue($this->engine->can('document', 'signing', 'signed'));
        $this->assertTrue($this->engine->can('document', 'signed', 'archived'));

        // назад и через шаг — нельзя
        $this->assertFalse($this->engine->can('document', 'draft', 'approved'));
        $this->assertFalse($this->engine->can('document', 'approved', 'submitted'));
        $this->assertFalse($this->engine->can('document', 'registered', 'approved'));
        $this->assertFalse($this->engine->can('document', 'archived', 'signed'));
        $this->assertFalse($this->engine->can('document', 'unknown', 'submitted'));
        $this->assertFalse($this->engine->can('document', 'draft', 'draft'));
    }

    #[Test]
    public function leave_and_payroll_transition_rules(): void
    {
        // отпуск: решение только из pending
        $this->assertTrue($this->engine->can('leave_request', 'pending', 'approved'));
        $this->assertTrue($this->engine->can('leave_request', 'pending', 'rejected'));
        $this->assertFalse($this->engine->can('leave_request', 'approved', 'rejected'));
        $this->assertFalse($this->engine->can('leave_request', 'rejected', 'approved'));
        $this->assertFalse($this->engine->can('leave_request', 'cancelled', 'approved'));

        // зарплата: пересчёт только из draft/calculated, выплата только из approved
        $this->assertTrue($this->engine->can('payroll_period', 'draft', 'calculated'));
        $this->assertTrue($this->engine->can('payroll_period', 'calculated', 'calculated'));
        $this->assertTrue($this->engine->can('payroll_period', 'calculated', 'approved'));
        $this->assertTrue($this->engine->can('payroll_period', 'approved', 'paid'));
        $this->assertFalse($this->engine->can('payroll_period', 'draft', 'approved'));
        $this->assertFalse($this->engine->can('payroll_period', 'calculated', 'paid'));
        $this->assertFalse($this->engine->can('payroll_period', 'paid', 'calculated'));
        $this->assertFalse($this->engine->can('payroll_period', 'paid', 'paid'));
    }

    #[Test]
    public function assert_throws_logic_exception_with_custom_message(): void
    {
        try {
            $this->engine->assert('payroll_period', 'draft', 'approved', 'Согласовать можно только рассчитанную ведомость');
            $this->fail('Должен был отклонить переход draft → approved');
        } catch (LogicException $e) {
            $this->assertSame('Согласовать можно только рассчитанную ведомость', $e->getMessage());
        }

        // разрешённый переход не бросает исключений
        $this->engine->assert('payroll_period', 'draft', 'calculated');
        $this->engine->assert('document', 'draft', 'submitted');
    }

    #[Test]
    public function assert_without_message_reports_subject_and_statuses(): void
    {
        try {
            $this->engine->assert('leave_request', 'approved', 'rejected');
            $this->fail('Должен был отклонить повторное решение');
        } catch (LogicException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
            $this->assertStringContainsString('rejected', $e->getMessage());
            $this->assertStringContainsString('leave_request', $e->getMessage());
        }
    }

    #[Test]
    public function record_writes_journal_and_history_is_ordered_per_subject(): void
    {
        $user = User::factory()->create();

        $this->engine->record('document', 42, null, 'submitted', 'submit', $user, null);
        $this->engine->record('document', 42, 'submitted', 'approved', 'approve_final', $user, 'ОК');
        $this->engine->record('document', 99, 'draft', 'submitted', 'submit', null, null); // чужой документ

        $history = $this->engine->history('document', 42);

        $this->assertCount(2, $history);
        $this->assertSame(['submitted', 'approved'], $history->pluck('to_status')->all());
        $this->assertSame(['submit', 'approve_final'], $history->pluck('action')->all());
        $this->assertSame($user->id, $history->first()->actor_user_id);
        $this->assertSame('ОК', $history->last()->comment);
        $this->assertNull($history->first()->from_status);

        // история изолирована по субъекту и типу
        $this->assertCount(1, $this->engine->history('document', 99));
        $this->assertCount(0, $this->engine->history('leave_request', 42));

        $this->assertDatabaseHas('workflow_transitions', [
            'subject_type' => 'document',
            'subject_id' => 42,
            'to_status' => 'approved',
            'action' => 'approve_final',
            'actor_user_id' => $user->id,
        ]);
    }
}
