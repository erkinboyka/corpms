<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Support\AiEndpointGuard;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-04 / SEC-08: адрес провайдера LLM нельзя перенаправить на внутреннюю сеть.
 * Проверяется и на записи настройки (422), и на реальном вызове (guard + без redirects).
 */
final class AiEndpointSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $company = Company::create(['name' => 'AI Safety Co', 'code' => 'aisafety']);
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'AI-SEC-1',
            'first_name' => 'Ирина',
            'last_name' => 'Безопасность',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->admin = $user;
        $this->actingAs($user);

        config(['corpms.ai.base_url' => 'https://llm.example.com/v1', 'corpms.ai.api_key' => 'test-key']);
    }

    public function test_private_and_metadata_endpoints_are_rejected_by_guard(): void
    {
        foreach ([
            'http://127.0.0.1:8080/v1',
            'http://localhost/v1',
            'http://169.254.169.254/latest/meta-data',
            'http://10.0.0.5/v1',
            'http://192.168.1.1/v1',
            'http://[::1]/v1',
            'http://user:pass@llm.example.com/v1',
            'http://llm.example.com.evil.invalid/v1',
        ] as $url) {
            self::assertFalse(AiEndpointGuard::isAllowed($url), $url.' должен быть запрещён');
        }

        self::assertTrue(AiEndpointGuard::isAllowed('https://llm.example.com/v1'));
    }

    public function test_settings_reject_private_ai_base_url(): void
    {
        $this->post('/settings', ['settings' => [
            'corpms.ai.base_url' => 'http://127.0.0.1:8080/v1',
        ]])->assertSessionHasErrors('settings.corpms.ai.base_url');

        self::assertDatabaseMissing('settings', [
            'key' => 'ai.base_url',
            'value' => 'http://127.0.0.1:8080/v1',
        ]);
    }

    public function test_settings_reject_metadata_service_base_url(): void
    {
        $this->post('/settings', ['settings' => [
            'corpms.ai.base_url' => 'http://169.254.169.254/latest/meta-data',
        ]])->assertSessionHasErrors('settings.corpms.ai.base_url');
    }

    public function test_redirect_to_private_address_is_not_followed(): void
    {
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1:8080/internal',
            ]),
        ]);

        $this->post('/ai', ['question' => 'Сколько задач в работе?'])->assertRedirect();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '127.0.0.1'));
    }
}
