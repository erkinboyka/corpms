<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Ai\Services\AiAssistantService;
use App\Domains\Biometric\Services\BiometricService;
use App\Models\AiConversation;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\SettingsService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Межтенантность настроек: строка settings с company_id — переопределение
 * компании, company_id NULL — общая настройка (fallback → config → default).
 *
 * Контекст компании: web — сотрудник auth()->user(), device API — компания
 * устройства, AI/очередь — компания сотрудника диалога.
 */
class SettingsCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected User $adminB;

    protected Employee $employeeA;

    protected Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->companyA = Company::create(['name' => 'Alpha Co', 'code' => 'alpha']);
        $this->companyB = Company::create(['name' => 'Beta Co', 'code' => 'beta']);

        $this->adminA = $this->superAdmin($this->companyA, 'A-1', 'Админ', 'Альфа');
        $this->adminB = $this->superAdmin($this->companyB, 'B-1', 'Админ', 'Бета');
    }

    protected function superAdmin(Company $company, string $no, string $first, string $last): User
    {
        $user = User::factory()->create();

        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);

        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        return $user;
    }

    /** Значение в явном контексте компании (без состояния предыдущих запросов). */
    protected function getForCompany(?int $companyId, string $key, mixed $default = null): mixed
    {
        $service = new SettingsService;
        $service->useCompany($companyId);

        return $service->get($key, $default);
    }

    /* -------------------- запись из web-панели -------------------- */

    public function test_settings_update_writes_company_row_and_stays_isolated(): void
    {
        $this->actingAs($this->adminA);

        $this->post('/settings', [
            'settings' => [
                'corpms.signature.otp_ttl' => 22,
                'corpms.biometric.require_liveness' => '1',
            ],
        ])->assertRedirect()->assertSessionHas('status');

        // строка сохранена с company_id компании автора
        $this->assertDatabaseHas('settings', [
            'group' => 'corpms',
            'key' => 'signature.otp_ttl',
            'value' => '22',
            'company_id' => $this->companyA->id,
        ]);

        // компания A видит своё значение
        self::assertSame(22, $this->getForCompany($this->companyA->id, 'corpms.signature.otp_ttl'));

        // компания B его не видит: значение config, а не 22
        self::assertSame(
            config('corpms.signature.otp_ttl'),
            $this->getForCompany($this->companyB->id, 'corpms.signature.otp_ttl'),
        );

        // общий контекст (company_id NULL) тоже не видит строку компании A
        self::assertSame(
            config('corpms.signature.otp_ttl'),
            $this->getForCompany(null, 'corpms.signature.otp_ttl'),
        );
    }

    public function test_settings_page_shows_scope_and_hides_foreign_overrides(): void
    {
        $this->actingAs($this->adminA);
        $this->post('/settings', ['settings' => ['corpms.signature.otp_ttl' => 22]])
            ->assertRedirect();

        $this->get('/settings')
            ->assertOk()
            ->assertSee('сохранено для вашей компании')
            ->assertSee('Значения сохраняются для вашей компании');

        // у компании B своих переопределений нет — только config
        $this->actingAs($this->adminB);
        $this->get('/settings')
            ->assertOk()
            ->assertSee('значение из config')
            ->assertDontSee('сохранено для вашей компании');
    }

    /* -------------------- fallback на общую настройку -------------------- */

    public function test_global_row_serves_as_fallback_until_company_overrides(): void
    {
        Setting::create([
            'group' => 'corpms',
            'key' => 'signature.otp_ttl',
            'value' => '9',
            'company_id' => null,
        ]);

        // общая настройка действует для компании без переопределения
        self::assertSame(9, $this->getForCompany($this->companyB->id, 'corpms.signature.otp_ttl'));

        // переопределение компании перекрывает общую, но не удаляет её
        $service = new SettingsService;
        $service->useCompany($this->companyA->id);
        $service->set('corpms.signature.otp_ttl', 22);

        self::assertSame(22, $this->getForCompany($this->companyA->id, 'corpms.signature.otp_ttl'));
        self::assertSame(9, $this->getForCompany($this->companyB->id, 'corpms.signature.otp_ttl'));
        self::assertSame(9, $this->getForCompany(null, 'corpms.signature.otp_ttl'));
    }

    /* -------------------- device API -------------------- */

    public function test_device_reads_liveness_setting_of_device_company(): void
    {
        // общая настройка: liveness обязателен
        Setting::create([
            'group' => 'corpms',
            'key' => 'biometric.require_liveness',
            'value' => '1',
            'company_id' => null,
        ]);
        // компания A отключила liveness для своих терминалов
        Setting::create([
            'group' => 'corpms',
            'key' => 'biometric.require_liveness',
            'value' => '0',
            'company_id' => $this->companyA->id,
        ]);

        $tokenA = $this->registerDevice($this->companyA, 'SN-A-1');
        $tokenB = $this->registerDevice($this->companyB, 'SN-B-1');

        $this->postEvent($tokenA, 'evt-scope-a')->assertStatus(422);
        $this->postEvent($tokenB, 'evt-scope-b')->assertStatus(422);

        // терминал компании A: liveness выключен в переопределении компании
        self::assertFalse(
            (bool) BiometricEvent::query()->where('event_uuid', 'evt-scope-a')->sole()->liveness_required,
            'Терминал компании A должен читать переопределение своей компании',
        );

        // терминал компании B: действует общая настройка
        self::assertTrue(
            (bool) BiometricEvent::query()->where('event_uuid', 'evt-scope-b')->sole()->liveness_required,
            'Терминал компании B не должен видеть настройку компании A',
        );
    }

    /* -------------------- AI: очередь без web-контекста -------------------- */

    public function test_ai_retry_uses_llm_settings_of_conversation_company(): void
    {
        // LLM настроен только для компании A (в phpunit.xml AI выключен)
        Setting::create([
            'group' => 'corpms',
            'key' => 'ai.base_url',
            'value' => 'https://llm.example.com/v1',
            'company_id' => $this->companyA->id,
        ]);
        Setting::create([
            'group' => 'corpms',
            'key' => 'ai.api_key',
            'value' => 'sk-company-a',
            'company_id' => $this->companyA->id,
        ]);

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Ответ из LLM компании A.']]],
            ]),
        ]);

        $conversationA = $this->conversation($this->employeeA());
        $conversationB = $this->conversation($this->employeeB());

        $service = app(AiAssistantService::class);

        // без web-контекста (очередь): диалог компании A находит свой LLM
        self::assertTrue($service->retryLlm($conversationA), 'Компания A должна использовать свой API-ключ');
        self::assertTrue((bool) $conversationA->refresh()->grounded);

        // диалог компании B не видит LLM-настройки компании A
        self::assertFalse($service->retryLlm($conversationB), 'Компания B не должна видеть ключ компании A');

        Http::assertSentCount(1);
    }

    /* -------------------- helpers -------------------- */

    protected function registerDevice(Company $company, string $serial): string
    {
        [, $token] = app(BiometricService::class)->registerDevice([
            'company_id' => $company->id,
            'name' => 'Terminal '.$serial,
            'serial_number' => $serial,
            'device_type' => 'face_terminal',
            'vendor' => 'generic',
            'direction' => 'both',
        ]);

        return (string) $token;
    }

    /** Событие неизвестного сотрудника: пишет liveness_required без расчёта табеля. */
    protected function postEvent(string $token, string $uuid): TestResponse
    {
        return $this->postJson('/api/v1/biometric/device/events', [
            'event_uuid' => $uuid,
            'employee_no' => 'NOBODY-'.$uuid,
            'direction' => 'in',
            'timestamp' => now()->toDateTimeString(),
            'liveness' => false,
        ], ['Authorization' => 'Bearer '.$token]);
    }

    protected function conversation(Employee $employee): AiConversation
    {
        return AiConversation::create([
            'employee_id' => $employee->id,
            'question' => 'Какая посещаемость?',
            'answer' => 'Локальный фолбэк',
            'query_used' => ['scope' => 'company'],
            'grounded' => false,
        ]);
    }

    protected function employeeA(): Employee
    {
        return Employee::query()->where('company_id', $this->companyA->id)->firstOrFail();
    }

    protected function employeeB(): Employee
    {
        return Employee::query()->where('company_id', $this->companyB->id)->firstOrFail();
    }
}
