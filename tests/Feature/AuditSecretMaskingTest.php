<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Services\AuditService;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4 (спринт по аудиту): секреты не попадают в audit_logs открытым текстом.
 *
 * 1. AuditService маскирует чувствительные ключи при записи (защита в глубину).
 * 2. Обновление настройки corpms.ai.api_key пишет в журнал маску, а не ключ.
 */
class AuditSecretMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $company = Company::create(['name' => 'Секреты', 'code' => 'secrets']);
        $this->adminUser = User::factory()->create();
        $this->adminEmployee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $this->adminUser->id,
            'employee_no' => 'SEC-1',
            'first_name' => 'Админ',
            'last_name' => 'Секретов',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    public function test_audit_service_masks_sensitive_values_on_write(): void
    {
        $service = app(AuditService::class);

        $log = $service->log(
            $this->adminUser,
            'settings.updated',
            'setting',
            null,
            ['corpms.ai.api_key' => 'sk-old-key', 'corpms.ai.model' => 'llama-3'],
            ['password' => 'hunter2', 'nested' => ['telegram_webhook_secret' => 'wh-secret']],
        );

        $old = $log->old_value;
        $new = $log->new_value;

        $this->assertSame('••••••', $old['corpms.ai.api_key']);
        $this->assertSame('llama-3', $old['corpms.ai.model']);
        $this->assertSame('••••••', $new['password']);
        $this->assertSame('••••••', $new['nested']['telegram_webhook_secret']);

        $raw = json_encode([$old, $new], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('sk-old-key', (string) $raw);
        $this->assertStringNotContainsString('hunter2', (string) $raw);
        $this->assertStringNotContainsString('wh-secret', (string) $raw);
    }

    public function test_audit_service_keeps_empty_secret_values_untouched(): void
    {
        $log = app(AuditService::class)->log(
            $this->adminUser,
            'settings.updated',
            'setting',
            null,
            ['corpms.ai.api_key' => null],
            ['corpms.ai.api_key' => ''],
        );

        $this->assertNull($log->old_value['corpms.ai.api_key']);
        $this->assertSame('', $log->new_value['corpms.ai.api_key']);
    }

    public function test_settings_update_does_not_write_api_key_to_audit(): void
    {
        $rawKey = 'sk-live-super-secret-key-123';

        $this->post('/settings', [
            'settings' => [
                'corpms.ai.api_key' => $rawKey,
                'corpms.ai.model' => 'llama-3.1-8b',
            ],
        ])->assertRedirect(route('settings.index'));

        $stored = AuditLog::query()->where('action', 'settings.updated')->latest('id')->first();

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString($rawKey, json_encode($stored->new_value, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame('••••••', $stored->new_value['corpms.ai.api_key']);
        $this->assertSame('llama-3.1-8b', $stored->new_value['corpms.ai.model']);
    }

    public function test_audit_index_does_not_render_raw_secret(): void
    {
        app(AuditService::class)->log(
            $this->adminUser,
            'settings.updated',
            'setting',
            null,
            null,
            ['corpms.ai.api_key' => 'sk-rendered-should-not-appear'],
        );

        $this->get('/audit')
            ->assertOk()
            ->assertDontSee('sk-rendered-should-not-appear');
    }
}
