<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Domains\Documents\Services\DocumentService;
use App\Domains\Documents\Services\SignatureService;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureRequest;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkflow;
use App\Models\DocumentWorkflowState;
use App\Models\DocumentWorkflowStep;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\QrToken;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Shared\Enums\SignatureStatus;
use App\Shared\Enums\WorkflowState;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ТЗ п.31–37: документы, версии, шаблоны, маршрут согласования, регистрация.
 * ТЗ п.61–63: подписи — internal hash, OTP, QR, верификация, отзыв.
 */
class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected Company $company;

    protected Employee $author;

    protected Employee $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = app(DocumentService::class);

        $this->company = Company::create(['name' => 'ООО Ромашка', 'code' => 'ro']);
        $this->author = $this->employee('EMP-1', 'Иван', 'Иванов');
        $this->director = $this->employee('EMP-2', 'Олег', 'Директоров');

        $this->attachRole($this->director, 'department_director');

        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00'));
    }

    /* ---------------- шаблоны и создание ---------------- */

    #[Test]
    public function workflow_step_employee_uses_assigned_employee_not_author(): void
    {
        $document = $this->makeDraftDocument();

        $workflow = DocumentWorkflow::create([
            'company_id' => $this->company->id,
            'name' => 'BL-21',
            'is_active' => true,
        ]);
        $step = DocumentWorkflowStep::create([
            'workflow_id' => $workflow->id,
            'step_order' => 1,
            'name' => 'Назначенный сотрудник',
            'actor_type' => 'employee',
            'employee_id' => $this->director->id,
            'required_action' => 'approve',
            'is_terminal' => true,
        ]);

        $actors = $this->service->actorsOfStep($step, $document);
        $this->assertCount(1, $actors);
        $this->assertSame($this->director->id, $actors->first()->id);
        $this->assertFalse($this->service->isActorOfStep($this->author, $step, $document));
        $this->assertTrue($this->service->isActorOfStep($this->director, $step, $document));

        $step->update(['employee_id' => null]);
        $this->assertCount(0, $this->service->actorsOfStep($step, $document));
    }

    #[Test]
    public function render_template_substitutes_variables_and_requires_all(): void
    {
        $category = DocumentCategory::create(['code' => 'CONTRACT', 'name' => 'Договоры']);
        $template = DocumentTemplate::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'name' => 'Служебная записка',
            'kind' => 'blade',
            'body' => 'Кому: {{ $employee_no }} | Дата: {{ $today_date }} | Контрагент: {{ $counterparty }}',
            'variables' => ['counterparty'],
        ]);

        $rendered = $this->service->renderTemplate($template, ['counterparty' => 'ООО Ветка'], $this->author);

        $this->assertStringContainsString('EMP-1', $rendered);
        $this->assertStringContainsString('01.11.2026', $rendered);
        $this->assertStringContainsString('ООО Ветка', $rendered);

        $this->expectException(LogicException::class);
        $this->service->renderTemplate($template, [], $this->author);
    }

    #[Test]
    public function create_from_template_makes_document_with_version(): void
    {
        $template = $this->makeTemplate();

        $document = $this->service->createFromTemplate($this->author, $template, ['amount' => '150000']);

        $this->assertSame('Д-2026-000001', $document->document_number);
        $this->assertSame('draft', $document->status->value);

        $version = DocumentVersion::query()->where('document_id', $document->id)->sole();
        $this->assertEquals(1, $version->version);
        $this->assertSame(hash('sha256', 'Сумма: 150000 руб.'), $version->sha256);
        $this->assertEquals($version->id, $document->fresh()->current_version_id);
    }

    #[Test]
    public function add_version_increments_and_updates_current(): void
    {
        $document = $this->makeDraftDocument();

        $v2 = $this->service->addVersion($document->fresh(), $this->author, null, bodyContent: 'Тело v2', changeNote: 'Правки');

        $this->assertEquals(2, $v2->version);
        $this->assertEquals($v2->id, $document->fresh()->current_version_id);
    }

    /* ---------------- согласование (workflow) ---------------- */

    #[Test]
    public function submit_creates_first_step_approval(): void
    {
        $document = $this->preparedDocument(false);

        $this->service->submit($this->author, $document->fresh());

        $document->refresh();
        $this->assertSame('submitted', $document->status->value);

        $pivot = DocumentWorkflowState::query()->where('document_id', $document->id)->first();
        $this->assertSame(WorkflowState::InProgress, $pivot->state);
        $this->assertEquals(1, $pivot->current_step);

        $this->assertDatabaseHas('document_approvals', [
            'document_id' => $document->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function full_approval_chain_reaches_approved(): void
    {
        $document = $this->preparedDocument(true);
        $this->service->submit($this->author, $document->fresh());

        $doc = $this->service->approve($this->author, $document->fresh());
        $this->assertSame('review', $doc->status->value); // перешли на шаг 2 (директор)

        $doc = $this->service->approve($this->director, $doc->fresh(), 'Согласовано');
        $this->assertSame('approved', $doc->status->value);

        $this->assertDatabaseHas('document_workflows_pivot', [
            'document_id' => $doc->id,
            'state' => 'completed',
        ]);
    }

    #[Test]
    public function double_approve_of_same_step_is_rejected(): void
    {
        $document = $this->preparedDocument(false);
        $this->service->submit($this->author, $document->fresh());

        $this->service->approve($this->author, $document->fresh());
        self::assertSame('approved', $document->fresh()->status->value);

        $this->expectException(LogicException::class);
        $this->service->approve($this->author, $document->fresh());
    }

    #[Test]
    public function unauthorized_approver_cannot_decide(): void
    {
        $document = $this->preparedDocument(false);
        $this->service->submit($this->author, $document->fresh());

        $outsider = $this->employee('EMP-3', 'Сидор', 'Сидоров');

        $this->expectException(LogicException::class);
        $this->service->approve($outsider, $document->fresh());
    }

    #[Test]
    public function rejection_sends_document_to_revision_and_resubmit_works(): void
    {
        $document = $this->preparedDocument(false);
        $this->service->submit($this->author, $document->fresh());

        $doc = $this->service->reject($this->author, $document->fresh(), 'Переписать тело');
        $this->assertSame('revision', $doc->status->value);

        $this->service->submit($this->author, $doc->fresh());
        $doc = $this->service->approve($this->author, $doc->fresh());

        $this->assertSame('approved', $doc->status->value);
    }

    #[Test]
    public function register_requires_approved_and_sets_reg_number(): void
    {
        $document = $this->approvedDocument();

        $doc = $this->service->register($this->author, $document);

        $this->assertSame('registered', $doc->status->value);
        $this->assertSame('Р-2026-000001', $doc->reg_number);

        $draft = $this->makeDraftDocument();
        $this->expectException(LogicException::class);
        $this->service->register($this->author, $draft);
    }

    /* ---------------- подпись (internal + OTP + QR) ---------------- */

    #[Test]
    public function signature_happy_path_without_otp(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id]);

        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->first();
        $version = $document->fresh()->currentVersion;

        $signature = $signatureService->sign($this->author, $request);

        $this->assertSame('SIG-2026-000001', $signature->signature_id);
        $this->assertSame($version->sha256, $signature->document_hash);
        $this->assertSame(SignatureStatus::Valid, $signature->status);
        $this->assertTrue((bool) $version->fresh()->immutable);
        $this->assertSame('signed', $document->fresh()->status->value);

        $this->assertSame('valid', $signatureService->verify($signature->fresh())->status->value);
    }

    #[Test]
    public function signed_version_is_immutable_and_signature_key_is_domain_separated(): void
    {
        $document = $this->approvedDocument();
        $signatures = app(SignatureService::class);
        $signatures->requestSignatures($this->director, $document, [$this->author->id]);
        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->sole();
        $signature = $signatures->sign($this->author, $request);

        $version = $document->fresh()->currentVersion;
        $originalHash = (string) $version->sha256;
        $this->assertTrue((bool) $version->immutable);
        $this->assertSame('valid', $signatures->verify($signature->fresh())->status->value);

        // SEC-03: подпись не строится напрямую на app.key
        $withAppKey = hash('sha256', implode('|', [
            $originalHash,
            $this->author->id,
            $signature->signature_id,
            config('app.key'),
        ]));
        $this->assertNotSame($withAppKey, $signature->signature_hash);

        // SEC-04: подписанная версия не меняется и не удаляется
        try {
            $version->update(['sha256' => str_repeat('b', 64)]);
            $this->fail('Подписанная версия не должна изменяться');
        } catch (LogicException) {
            // ожидаемо
        }
        $this->assertSame($originalHash, $version->fresh()->sha256);

        try {
            $this->service->addVersion($document->fresh(), $this->author, null, bodyContent: 'Правка после подписи');
            $this->fail('Подписанный документ не должен получать новую версию');
        } catch (LogicException) {
            // ожидаемо
        }
        $this->assertSame(1, DocumentVersion::query()->where('document_id', $document->id)->count());
    }

    #[Test]
    public function signature_otp_flow(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id], requiresOtp: true);

        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->first();
        $code = $signatureService->issueOtp($request);

        // неверный код
        try {
            $signatureService->sign($this->author, $request->fresh(), otp: '000000');
            $this->fail('Должен был отклонить неверный OTP');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        // верный
        $signature = $signatureService->sign($this->author, $request->fresh(), otp: $code);
        $this->assertSame('valid', $signature->status->value);
    }

    #[Test]
    public function internal_otp_is_blocked_after_five_bad_codes(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);
        $signatureService->requestSignatures($this->director, $document, [$this->author->id], requiresOtp: true);

        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->firstOrFail();
        $code = $signatureService->issueOtp($request);

        for ($i = 0; $i < 5; $i++) {
            try {
                $signatureService->sign($this->author, $request->fresh(), otp: '000000');
                $this->fail('Неверный OTP должен отклоняться');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $meta = $request->fresh()->meta ?? [];
        $this->assertSame(5, (int) ($meta['otp_attempts'] ?? 0));
        $this->assertNotEmpty($meta['otp_blocked_at'] ?? null);

        try {
            $signatureService->sign($this->author, $request->fresh(), otp: $code);
            $this->fail('Заблокированный OTP не должен принимать даже верный код');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(5, AuditLog::query()->where('action', 'signature.otp_failed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'signature.otp_blocked')->count());
    }

    /** OTP выпускается автоматически при запросе подписи и доставляется подписанту. */
    #[Test]
    public function signature_otp_is_issued_on_request_and_delivered(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id], requiresOtp: true);

        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->firstOrFail();

        // код уже выпущен: без этого verifyOtp() всегда отвечал «OTP-код не выдан»
        $this->assertNotEmpty($request->meta['otp_code'] ?? null, 'OTP должен быть выпущен при создании запроса');
        $this->assertNotEmpty($request->meta['otp_expires_at'] ?? null);

        // подписанту ушло уведомление с кодом
        $this->assertDatabaseHas('attendance_notifications', [
            'recipient_id' => $this->author->id,
            'type' => 'signature.otp',
        ]);

        // подпись по этому запросу проходит в обход повторной выдачи
        $this->assertSame('pending', $request->status->value);
    }

    #[Test]
    public function signature_rejected_before_approval(): void
    {
        $document = $this->makeDraftDocument();
        $signatureService = app(SignatureService::class);

        $this->expectException(LogicException::class);
        $signatureService->requestSignatures($this->author, $document, [$this->author->id]);
    }

    #[Test]
    public function tampered_version_fails_verification(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id]);
        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->first();
        $signature = $signatureService->sign($this->author, $request);

        // «правка» корпуса документа после подписи
        DocumentVersion::query()->whereKey($signature->document_version_id)->update([
            'sha256' => hash('sha256', 'подделано'),
        ]);

        $result = $signatureService->verify($signature->fresh());

        $this->assertSame('invalid', $result->status->value);
    }

    #[Test]
    public function revoke_signature(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id]);
        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->first();
        $signature = $signatureService->sign($this->author, $request);

        $revoked = $signatureService->revoke($this->director, $signature, 'По служебной необходимости');

        $this->assertSame('revoked', $revoked->status->value);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'signature.revoked']);
    }

    #[Test]
    public function decline_signature_sends_document_to_revision(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id]);
        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->first();

        $declined = $signatureService->decline($this->author, $request, 'Не согласен с условиями');

        $this->assertSame('declined', $declined->status->value);
        $this->assertSame('revision', $document->fresh()->status->value);
    }

    #[Test]
    public function qr_token_signing_one_time(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id], requiresOtp: false, driver: 'qr_approval');
        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->first();

        $plain = 'qr_'.Str::random(40);
        QrToken::create([
            'created_by' => $this->author->id,
            'token_hash' => hash('sha256', $plain),
            'subject_type' => DocumentSignatureRequest::class,
            'subject_id' => $request->id,
            'purpose' => 'sign',
            'one_time' => true,
            'expires_at' => now()->addDays(7),
        ]);

        $signature = $signatureService->signByQrToken($this->author, $request, $plain);
        $this->assertSame('valid', $signature->status->value);
        $this->assertSame('signed', $document->fresh()->status->value);

        // второй раз: токен one_time уже использован
        $this->expectException(RuntimeException::class);
        $signatureService->signByQrToken($this->author, $request, $plain);
    }

    #[Test]
    public function qr_token_claim_is_rolled_back_when_signing_fails(): void
    {
        $document = $this->approvedDocument();
        $signatureService = app(SignatureService::class);

        $signatureService->requestSignatures($this->director, $document, [$this->author->id], requiresOtp: false, driver: 'qr_approval');
        $request = DocumentSignatureRequest::query()->where('document_id', $document->id)->first();

        $plain = 'qr_'.Str::random(40);
        QrToken::create([
            'created_by' => $this->author->id,
            'token_hash' => hash('sha256', $plain),
            'subject_type' => DocumentSignatureRequest::class,
            'subject_id' => $request->id,
            'purpose' => 'sign',
            'one_time' => true,
            'expires_at' => now()->addDays(7),
        ]);

        // Состояние, при котором sign() обязан отказать: запрос уже закрыт.
        $request->update(['status' => 'signed']);

        try {
            $signatureService->signByQrToken($this->author, $request->fresh(), $plain);
            $this->fail('Подпись должна быть отклонена');
        } catch (LogicException) {
            // ожидаемо
        }

        $this->assertNull(QrToken::query()->where('token_hash', hash('sha256', $plain))->sole()->used_at);
        $this->assertSame(0, DocumentSignature::query()->count());

        // Токен не потерян: после восстановления запроса им можно подписать.
        $request->update(['status' => 'pending']);
        $signature = $signatureService->signByQrToken($this->author, $request->fresh(), $plain);
        $this->assertSame('valid', $signature->status->value);
        $this->assertSame(1, DocumentSignature::query()->count());
        $this->assertNotNull(QrToken::query()->where('token_hash', hash('sha256', $plain))->sole()->used_at);
    }

    #[Test]
    public function prepare_qr_signature_rejects_status_outside_quick_qr_transition(): void
    {
        $document = $this->preparedDocument(false);
        $this->service->submit($this->author, $document->fresh());

        $this->assertSame('submitted', $document->fresh()->status->value);
        $this->expectException(LogicException::class);

        try {
            app(SignatureService::class)->prepareQrSignature($this->author, $document->fresh());
        } finally {
            // Невалидный переход не должен попасть в историю и не менять статус.
            $this->assertSame('submitted', $document->fresh()->status->value);
            $this->assertSame(0, WorkflowTransition::query()
                ->where('subject_type', 'document')
                ->where('subject_id', $document->id)
                ->where('to_status', 'signing')
                ->count());
        }
    }

    /* ---------------- helpers ---------------- */

    protected function makeTemplate(): DocumentTemplate
    {
        $category = DocumentCategory::create(['code' => 'ACT', 'name' => 'Акты']);

        return DocumentTemplate::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'name' => 'Акт сверки',
            'kind' => 'blade',
            'body' => 'Сумма: {{ $amount }} руб.',
            'variables' => ['amount'],
        ]);
    }

    protected function employee(string $no, string $first, string $last): Employee
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'department_id' => Department::query()->firstOrCreate(
                ['company_id' => $this->company->id, 'code' => 'MAIN'],
                ['name' => 'Штаб']
            )->id,
        ]);

        return $employee;
    }

    protected function attachRole(Employee $employee, string $slug): void
    {
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    protected function makeDraftDocument(): Document
    {
        $category = DocumentCategory::query()->firstOrCreate(
            ['code' => 'DOC'],
            ['name' => 'Прочее']
        );
        $document = new Document([
            'company_id' => $this->company->id,
            'document_number' => 'Д-2026-00000'.(Document::query()->count() + 1),
            'category_id' => $category->id,
            'title' => 'Test',
            'author_id' => $this->author->id,
        ]);
        $document->save();

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version' => 1,
            'sha256' => hash('sha256', 'Тело v1'),
            'created_by' => $this->author->id,
        ]);
        $document->update(['current_version_id' => $version->id]);
        $document->refresh();

        return $document;
    }

    protected function makeWorkflow(bool $twoSteps): DocumentWorkflow
    {
        $workflow = DocumentWorkflow::create([
            'company_id' => $this->company->id,
            'name' => 'Стандарт',
            'is_active' => true,
        ]);
        DocumentWorkflowStep::create([
            'workflow_id' => $workflow->id,
            'step_order' => 1,
            'name' => 'Автор',
            'actor_type' => 'author',
            'required_action' => 'approve',
        ]);
        if ($twoSteps) {
            DocumentWorkflowStep::create([
                'workflow_id' => $workflow->id,
                'step_order' => 2,
                'name' => 'Директор отдела',
                'actor_type' => 'role',
                'role_id' => Role::query()->where('slug', 'department_director')->value('id'),
                'required_action' => 'approve',
                'is_terminal' => true,
            ]);
        }
        $workflow->refresh();

        return $workflow;
    }

    protected function preparedDocument(bool $twoSteps): Document
    {
        $document = $this->makeDraftDocument();
        $this->makeWorkflow($twoSteps);

        return $document;
    }

    protected function approvedDocument(): Document
    {
        $document = $this->preparedDocument(false);
        $this->service->submit($this->author, $document->fresh());
        $doc = $this->service->approve($this->author, $document->fresh());

        $doc->refresh();

        return $doc;
    }
}
