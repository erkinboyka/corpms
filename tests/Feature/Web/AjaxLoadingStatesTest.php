<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C5: состояния загрузки AJAX-блоков — серверная разметка aria-busy/role=status,
 * которую JS переключает во время фоновых запросов (поиск, AI-панель).
 */
class AjaxLoadingStatesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Loading Co', 'code' => 'loadingco']);
        $this->adminUser = User::factory()->create();
        $this->adminEmployee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'employee_no' => 'A-1',
            'first_name' => 'Админ',
            'last_name' => 'Админов',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    public function test_search_panel_renders_aria_busy_marker(): void
    {
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        // панель живого поиска стартует не-busy; JS ставит aria-busy="true" на время fetch
        $this->assertMatchesRegularExpression(
            '/id="crm-search-panel"[^>]*aria-busy="false"/',
            $html,
            'Панель поиска должна иметь стартовый aria-busy="false"'
        );
    }

    public function test_ai_panel_renders_aria_busy_and_typing_status(): void
    {
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        // поток сообщений AI-панели: aria-busy переключается setBusy() на время запроса
        $this->assertMatchesRegularExpression(
            '/id="ai-messages"[^>]*aria-busy="false"/',
            $html,
            'Поток сообщений AI-панели должен иметь стартовый aria-busy="false"'
        );

        // индикатор «печатает» — live-регион для скринридеров
        $this->assertMatchesRegularExpression(
            '/id="ai-typing"[^>]*role="status"/',
            $html,
            'Индикатор ответа AI должен быть role="status"'
        );
    }

    public function test_kanban_page_carries_busy_hooks_for_js(): void
    {
        // карточка — точка, на которую JS вешает aria-busy во время PUT-запроса
        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Карточка для busy-состояния',
            'number' => 'T-C5-1',
            'author_id' => $this->adminEmployee->id,
            'status' => 'new',
            'priority' => 'medium',
        ]);

        $html = (string) $this->get('/tasks')->assertOk()->getContent();

        $this->assertStringContainsString('data-kanban-move', $html);
        $this->assertStringContainsString('data-task-id', $html);
    }
}
