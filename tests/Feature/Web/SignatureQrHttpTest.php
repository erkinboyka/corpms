<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentSignature;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\QrToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Быстрая подпись документа по QR: generating → публичная страница → подпись без входа.
 */
class SignatureQrHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected Employee $employee;

    protected string $lastCode = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'QR Co', 'code' => 'qrco']);
        $this->user = User::factory()->create();
        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'employee_no' => 'QR-1',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->actingAs($this->user);
    }

    protected function makeDocument(string $title): Document
    {
        $category = DocumentCategory::create(['code' => 'QR'.random_int(100, 999), 'name' => 'QR документы']);
        $document = Document::create([
            'company_id' => $this->company->id,
            'document_number' => 'Д-QR-'.random_int(1000, 9999),
            'category_id' => $category->id,
            'title' => $title,
            'body' => 'Тело документа для подписи по QR',
            'author_id' => $this->employee->id,
            'status' => 'draft',
            'confidentiality' => 'internal',
        ]);
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version' => 1,
            'sha256' => str_repeat('a', 64),
            'created_by' => $this->employee->id,
        ]);
        $document->update(['current_version_id' => $version->id]);

        return $document->refresh();
    }

    protected function issueToken(Document $document): string
    {
        $response = $this->post('/documents/'.$document->id.'/signature-qr')->assertRedirect();
        $response->assertSessionHas('signature_qr');
        $qr = $response->getSession()->get('signature_qr');
        $this->assertStringContainsString('<svg', $qr['svg']);
        // SEC-02: код подтверждения не должен попадать во flash-сессию
        $this->assertArrayNotHasKey('code', $qr);

        $notification = AppNotification::query()->where('type', 'signature.otp')->latest()->first();
        $this->assertNotNull($notification);
        preg_match('/\b\d{6}\b/', (string) $notification->body, $match);
        $this->assertNotEmpty($match, 'Код подтверждения должен прийти в уведомлении');
        $this->lastCode = $match[0];
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastCode);

        return (string) basename((string) parse_url($qr['url'], PHP_URL_PATH));
    }

    public function test_qr_signing_full_flow_without_login(): void
    {
        $document = $this->makeDocument('Договор для QR подписи');
        $token = $this->issueToken($document);

        $this->assertSame(1, QrToken::query()->where('purpose', 'sign')->count());
        $this->assertSame('signing', $document->refresh()->status->value);

        // Ссылка из QR открывается и подписывается без входа в CRM.
        $this->app['auth']->guard()->logout();
        $this->assertGuest();

        $this->get('/sign/'.$token)
            ->assertOk()
            ->assertSee('Договор для QR подписи')
            ->assertSee('Код подтверждения')
            ->assertSee('Подписать');

        $this->post('/sign/'.$token, ['otp_code' => $this->lastCode])->assertRedirect();

        $signature = DocumentSignature::query()->sole();
        $this->assertSame('qr_approval', $signature->signature_type->value);
        $this->assertSame('valid', $signature->status->value);
        $this->assertSame('Петров Иван', $signature->signer->last_name.' '.$signature->signer->first_name);
        $this->assertSame('signed', $document->refresh()->status->value);
        $this->assertTrue($document->currentVersion->fresh()->immutable);
        $this->assertNotNull(QrToken::query()->where('token_hash', hash('sha256', $token))->sole()->used_at);

        // После подписи тот же токен показывает результат, а не ошибку.
        $this->get('/sign/'.$token)
            ->assertOk()
            ->assertSee('Документ подписан')
            ->assertSee($signature->signature_id);
    }

    public function test_qr_page_shows_deadlines_but_not_document_body(): void
    {
        $document = $this->makeDocument('QR с содержимым');
        $document->update(['body' => '<p>Тело для проверки перед подписью</p><script>alert(1)</script>']);
        $token = $this->issueToken($document);

        $this->app['auth']->guard()->logout();

        $this->get('/sign/'.$token)
            ->assertOk()
            ->assertSee('QR-ссылка действительна до')
            ->assertSee('Код подтверждения действует до')
            // SEC-02: содержимое документа не утекает на публичную страницу подписи
            ->assertDontSee('Тело для проверки перед подписью')
            ->assertDontSee('<script>alert(1)</script>', false);

        QrToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->update(['expires_at' => now()->subMinute()]);

        $this->get('/sign/'.$token)
            ->assertOk()
            ->assertSee('QR-код недействителен')
            ->assertDontSee('Тело для проверки перед подписью');
    }

    public function test_qr_signing_requires_delivered_code(): void
    {
        $document = $this->makeDocument('Подпись только по коду');
        $token = $this->issueToken($document);

        $notification = AppNotification::query()
            ->where('type', 'signature.otp')->latest()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString($this->lastCode, (string) $notification->body);

        $this->app['auth']->guard()->logout();
        $this->post('/sign/'.$token)->assertSessionHasErrors('field');
        $this->post('/sign/'.$token, ['otp_code' => '000000'])->assertSessionHasErrors('field');
        $this->assertSame(0, DocumentSignature::query()->count());

        $this->post('/sign/'.$token, ['otp_code' => $this->lastCode])->assertRedirect();
        $this->assertSame(1, DocumentSignature::query()->count());
    }

    public function test_five_bad_codes_revoke_qr_token_and_are_audited(): void
    {
        $document = $this->makeDocument('QR с лимитом OTP');
        $token = $this->issueToken($document);

        $this->app['auth']->guard()->logout();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/sign/'.$token, ['otp_code' => '000000'])->assertSessionHasErrors('field');
        }

        $qr = QrToken::query()->where('token_hash', hash('sha256', $token))->sole();
        $this->assertSame(5, (int) ($qr->meta['otp_attempts'] ?? 0));
        $this->assertNotNull($qr->revoked_at);

        // Даже правильный код больше не помогает.
        $this->post('/sign/'.$token, ['otp_code' => $this->lastCode])->assertSessionHasErrors('field');
        $this->assertSame(0, DocumentSignature::query()->count());

        $this->assertSame(5, AuditLog::query()->where('action', 'signature.otp_failed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'signature.otp_blocked')->count());
    }

    public function test_qr_token_is_one_time_and_cannot_sign_twice(): void
    {
        $document = $this->makeDocument('Одноразовая QR подпись');
        $token = $this->issueToken($document);

        $this->app['auth']->guard()->logout();
        $this->post('/sign/'.$token, ['otp_code' => $this->lastCode])->assertRedirect();
        $this->post('/sign/'.$token, ['otp_code' => $this->lastCode])->assertSessionHasErrors('field');

        $this->assertSame(1, DocumentSignature::query()->count());
    }

    public function test_unknown_qr_token_shows_invalid_page(): void
    {
        $this->app['auth']->guard()->logout();

        $this->get('/sign/'.str_repeat('x', 48))
            ->assertOk()
            ->assertSee('QR-код недействителен');
    }

    public function test_preparing_qr_requires_sign_permission(): void
    {
        $document = $this->makeDocument('QR без прав');
        $this->employee->roles()->detach();
        $this->app['auth']->guard()->logout();
        $this->actingAs($this->user);

        $this->post('/documents/'.$document->id.'/signature-qr')->assertForbidden();
        $this->assertSame(0, QrToken::query()->count());
    }

    public function test_qr_cannot_be_prepared_for_foreign_company_document(): void
    {
        $document = $this->makeDocument('Чужой документ');

        $otherCompany = Company::create(['name' => 'Other Co', 'code' => 'otherco']);
        $foreign = Employee::create([
            'company_id' => $otherCompany->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'F-1',
            'first_name' => 'Иностранец',
            'last_name' => 'Чужой',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $foreign->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->app['auth']->guard()->logout();
        $this->actingAs($foreign->user);

        $this->post('/documents/'.$document->id.'/signature-qr')->assertForbidden();
        $this->assertSame(0, QrToken::query()->count());
    }

    public function test_signed_document_cannot_be_prepared_for_qr_again(): void
    {
        $document = $this->makeDocument('Уже подписанный документ');
        $token = $this->issueToken($document);

        $this->app['auth']->guard()->logout();
        $this->post('/sign/'.$token, ['otp_code' => $this->lastCode])->assertRedirect();

        $this->app['auth']->guard()->login($this->user);
        $this->post('/documents/'.$document->id.'/signature-qr')->assertSessionHasErrors('field');
        $this->assertSame(1, QrToken::query()->count());
    }

    public function test_new_qr_revokes_previous_unused_token(): void
    {
        $document = $this->makeDocument('Две попытки QR');
        $first = $this->issueToken($document);
        $second = $this->issueToken($document);

        $this->assertNotSame($first, $second);
        $firstToken = QrToken::query()->where('token_hash', hash('sha256', $first))->sole();
        $this->assertNotNull($firstToken->revoked_at);

        $this->app['auth']->guard()->logout();
        $this->get('/sign/'.$first)->assertOk()->assertSee('QR-код недействителен');
        $this->get('/sign/'.$second)->assertOk()->assertSee('Подписать');
    }
}
