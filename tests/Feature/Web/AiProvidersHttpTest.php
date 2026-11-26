<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Ai\Services\AiProviderService;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Support\SettingsService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Несколько провайдеров/ключей AI: запись только в свою компанию,
 * выбор модели определяет ключ, старые corpms.ai.* остаются fallback-ом.
 */
class AiProvidersHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config()->set('corpms.ai.allowed_hosts', ['api.groq.com', 'openrouter.ai']);

        $this->companyA = Company::create(['name' => 'Alpha', 'code' => 'ai-a']);
        $this->companyB = Company::create(['name' => 'Beta', 'code' => 'ai-b']);
        $this->adminA = $this->admin($this->companyA, 'AI-1');
        $this->adminB = $this->admin($this->companyB, 'AI-2');
    }

    public function test_settings_page_saves_multiple_providers_and_masks_keys_in_audit(): void
    {
        $this->actingAs($this->adminA);

        $this->post('/settings', [
            'ai_providers' => [
                [
                    'name' => 'Groq основной',
                    'base_url' => 'https://api.groq.com/openai/v1',
                    'api_key' => 'sk-groq-secret',
                    'models' => 'openai/gpt-oss-120b, llama-3.3-70b',
                    'default_model' => 'openai/gpt-oss-120b',
                    'image_model' => '',
                    'embedding_model' => '',
                    'timeout' => 30,
                ],
                [
                    'name' => 'OpenRouter резерв',
                    'base_url' => 'https://openrouter.ai/api/v1',
                    'api_key' => 'sk-or-secret',
                    'models' => 'meta-llama/llama-3.3-70b',
                    'default_model' => 'meta-llama/llama-3.3-70b',
                    'image_model' => '',
                    'embedding_model' => '',
                    'timeout' => 45,
                ],
            ],
            'ai_active_provider' => 'new:1',
        ])->assertRedirect()->assertSessionHas('status');

        self::assertSame(2, AiProvider::query()->where('company_id', $this->companyA->id)->count());
        $this->assertDatabaseHas('ai_providers', [
            'company_id' => $this->companyA->id,
            'name' => 'OpenRouter резерв',
            'is_active' => 1,
        ]);
        $this->assertDatabaseMissing('ai_providers', ['company_id' => $this->companyB->id]);

        $stored = AiProvider::query()->where('name', 'Groq основной')->firstOrFail();
        self::assertStringStartsWith('enc:v1:', $stored->getRawOriginal('api_key'));
        self::assertSame('sk-groq-secret', $stored->api_key);
        self::assertSame(['openai/gpt-oss-120b', 'llama-3.3-70b'], $stored->modelList());

        $audit = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('action', 'settings.updated')
            ->value('new_value');
        self::assertIsString($audit);
        self::assertStringNotContainsString('sk-groq-secret', $audit);
        self::assertStringNotContainsString('sk-or-secret', $audit);
    }

    public function test_provider_of_another_company_is_not_visible_or_editable(): void
    {
        $this->actingAs($this->adminA);
        $this->post('/settings', [
            'ai_providers' => [[
                'name' => 'Только А',
                'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => 'sk-a',
                'models' => 'model-a',
                'default_model' => 'model-a',
                'timeout' => 30,
            ]],
            'ai_active_provider' => 'new:0',
        ])->assertRedirect();

        $foreign = AiProvider::query()->firstOrFail();

        $this->actingAs($this->adminB);
        $this->get('/settings')->assertDontSee('Только А');
        $this->post('/settings', [
            'ai_providers' => [[
                'id' => $foreign->id,
                'name' => 'Взлом чужого ключа',
                'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => 'sk-b',
                'models' => 'model-b',
                'default_model' => 'model-b',
                'timeout' => 30,
            ]],
            'ai_active_provider' => 'new:0',
        ])->assertRedirect();

        self::assertSame('Только А', $foreign->fresh()->name);
        self::assertSame($this->companyA->id, (int) $foreign->fresh()->company_id);
        self::assertSame(1, AiProvider::query()->where('company_id', $this->companyB->id)->count());
    }

    public function test_model_selects_its_own_provider_key_and_legacy_settings_are_fallback(): void
    {
        $this->actingAs($this->adminA);
        $this->post('/settings', [
            'ai_providers' => [
                [
                    'name' => 'Groq',
                    'base_url' => 'https://api.groq.com/openai/v1',
                    'api_key' => 'sk-groq',
                    'models' => 'groq-model',
                    'default_model' => 'groq-model',
                    'timeout' => 30,
                ],
                [
                    'name' => 'OpenRouter',
                    'base_url' => 'https://openrouter.ai/api/v1',
                    'api_key' => 'sk-or',
                    'models' => 'or-model',
                    'default_model' => 'or-model',
                    'timeout' => 30,
                ],
            ],
            'ai_active_provider' => 'new:0',
        ])->assertRedirect();

        $service = app(AiProviderService::class);
        self::assertSame('sk-groq', $service->credentials()['api_key']);
        self::assertSame('sk-or', $service->credentials('or-model')['api_key']);
        self::assertSame('or-model', $service->credentials('or-model')['model']);
        self::assertContains('groq-model', $service->availableModels());
        self::assertContains('or-model', $service->availableModels());

        $this->actingAs($this->adminB);
        $legacy = app(SettingsService::class);
        $legacy->set('corpms.ai.base_url', 'https://api.groq.com/openai/v1');
        $legacy->set('corpms.ai.api_key', 'sk-legacy');
        $legacy->set('corpms.ai.model', 'legacy-model');

        self::assertSame('sk-legacy', app(AiProviderService::class)->credentials()['api_key']);
    }

    protected function admin(Company $company, string $no): User
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => $no,
            'first_name' => 'AI',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        return $user;
    }
}
