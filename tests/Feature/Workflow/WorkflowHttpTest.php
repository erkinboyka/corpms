<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Domains\Workflow\WorkflowEngine;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentWorkflow;
use App\Models\DocumentWorkflowStep;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Этап B: документы через единый движок согласований —
 * журнал переходов, таймлайн в карточке и ошибки вместо 500.
 */
class WorkflowHttpTest extends TestCase
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
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Админов', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->actingAs($this->adminUser);

        // workflow: один шаг — автор (терминальный)
        DocumentWorkflow::create([
            'company_id' => $this->company->id,
            'name' => 'Стандарт',
            'is_active' => true,
        ]);
        DocumentWorkflowStep::create([
            'workflow_id' => DocumentWorkflow::max('id'),
            'step_order' => 1,
            'name' => 'Автор',
            'actor_type' => 'author',
            'required_action' => 'approve',
            'is_terminal' => true,
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

    private function makeDocument(string $title): Document
    {
        $category = DocumentCategory::create(['code' => 'ORD', 'name' => 'Приказы']);
        $this->post('/documents', [
            'title' => $title,
            'category_id' => $category->id,
            'body' => 'Тело приказа',
        ])->assertRedirect();

        return Document::query()->where('title', $title)->sole();
    }

    #[Test]
    public function lifecycle_records_transition_journal_and_shows_timeline(): void
    {
        $document = $this->makeDocument('Приказ №1');

        $this->post('/documents/'.$document->id.'/submit')->assertRedirect()->assertSessionHas('status');
        $this->post('/documents/'.$document->id.'/decide', ['decision' => 'approved'])->assertRedirect()->assertSessionHas('status');
        $this->post('/documents/'.$document->id.'/register')->assertRedirect()->assertSessionHas('status');
        $this->post('/documents/'.$document->id.'/archive')->assertRedirect()->assertSessionHas('status');

        $this->assertSame('archived', $document->refresh()->status->value);

        $history = app(WorkflowEngine::class)->history('document', $document->id);
        $this->assertSame(
            ['submit', 'approve_final', 'register', 'archive'],
            $history->pluck('action')->all(),
        );
        $this->assertSame(
            ['draft', 'submitted', 'approved', 'registered'],
            $history->pluck('from_status')->all(),
        );
        $this->assertSame(
            ['submitted', 'approved', 'registered', 'archived'],
            $history->pluck('to_status')->all(),
        );
        $this->assertSame($this->adminUser->id, $history->first()->actor_user_id);
        $this->assertSame($this->adminUser->id, $history->last()->actor_user_id);

        // таймлайн виден в карточке документа
        $this->get('/documents/'.$document->id)
            ->assertOk()
            ->assertSee('История согласования')
            ->assertSee('Отправлено на согласование')
            ->assertSee('Зарегистрировано')
            ->assertSee('В архив');
    }

    #[Test]
    public function leave_decision_is_guarded_by_engine_and_journaled(): void
    {
        // заявка на себя
        $this->post('/leaves', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-05',
            'type' => 'vacation',
            'reason' => 'Отдых',
        ])->assertRedirect();

        $leave = LeaveRequest::query()->sole();
        $this->assertSame('pending', $leave->status->value);

        // журнал: создание заявки (null → pending)
        $history = app(WorkflowEngine::class)->history('leave_request', $leave->id);
        $this->assertSame(['request'], $history->pluck('action')->all());
        $this->assertSame([null], $history->pluck('from_status')->all());
        $this->assertSame(['pending'], $history->pluck('to_status')->all());

        // согласование
        $this->put('/leaves/'.$leave->id.'/decision', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('approved', $leave->refresh()->status->value);

        // повторное решение — ошибка формы (не 500), статус не меняется
        $decision = $this->put('/leaves/'.$leave->id.'/decision', ['decision' => 'rejected'])
            ->assertRedirect()
            ->assertSessionHasErrors('field');
        $this->assertSame('approved', $leave->refresh()->status->value);

        // переносим session-cookie на GET, как это делает браузер (в тестах cookie сами не переносятся)
        $sessionCookie = collect($decision->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($sessionCookie, 'Session cookie missing on redirect response');
        $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue());

        // ошибка показана на странице
        $this->get('/leaves')
            ->assertOk()
            ->assertSee('По заявке уже принято решение');

        $history = app(WorkflowEngine::class)->history('leave_request', $leave->id);
        $this->assertSame(['request', 'approve'], $history->pluck('action')->all());
        $this->assertSame([null, 'pending'], $history->pluck('from_status')->all());
        $this->assertSame(['pending', 'approved'], $history->pluck('to_status')->all());
        $this->assertSame($this->adminUser->id, $history->last()->actor_user_id);
    }

    #[Test]
    public function invalid_transitions_become_form_errors_not_500(): void
    {
        $document = $this->makeDocument('Приказ №2');

        // черновик нельзя зарегистрировать
        $this->post('/documents/'.$document->id.'/register')
            ->assertRedirect()
            ->assertSessionHasErrors('field');
        $this->assertSame('draft', $document->refresh()->status->value);

        // повторная отправка на согласование
        $this->post('/documents/'.$document->id.'/submit')->assertRedirect();
        $this->post('/documents/'.$document->id.'/submit')
            ->assertRedirect()
            ->assertSessionHasErrors('field');
        $this->assertSame('submitted', $document->refresh()->status->value);

        // повторное решение после согласования
        $this->post('/documents/'.$document->id.'/decide', ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $document->refresh()->status->value);
        $this->post('/documents/'.$document->id.'/decide', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHasErrors('field');
        $this->assertSame('approved', $document->refresh()->status->value);

        // повторная архивация
        $this->post('/documents/'.$document->id.'/register')->assertRedirect();
        $this->post('/documents/'.$document->id.'/archive')->assertRedirect();
        $this->post('/documents/'.$document->id.'/archive')
            ->assertRedirect()
            ->assertSessionHasErrors('field');
        $this->assertSame('archived', $document->refresh()->status->value);

        // неудачные переходы не оставляют лишних записей журнала
        $history = app(WorkflowEngine::class)->history('document', $document->id);
        $this->assertSame(
            ['submit', 'approve_final', 'register', 'archive'],
            $history->pluck('action')->all(),
        );
    }
}
