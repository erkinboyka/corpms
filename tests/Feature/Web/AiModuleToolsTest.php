<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Accounting\Services\AccountingService;
use App\Domains\Ai\Services\AiToolRegistry;
use App\Domains\Bi\Services\BiService;
use App\Domains\Inventory\Services\InventoryService;
use App\Models\AccAccount;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CrmClient;
use App\Models\CrmDeal;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\FinAccount;
use App\Models\FinTransaction;
use App\Models\Product;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\Warehouse;
use App\Shared\Enums\StockMovementType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E7 (AI Copilot): инструменты новых модулей ТЗ — CRM, Finance, Inventory,
 * Projects, Accounting, BI. Проверяем права, чтение company-данных,
 * обработку аргументов и аудит вызовов/отказов.
 */
class AiModuleToolsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected const NEW_TOOLS = [
        'crm_pipeline_summary',
        'crm_clients_search',
        'finance_summary',
        'inventory_stock_summary',
        'projects_summary',
        'accounting_trial_balance',
        'bi_metrics_summary',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'AI Modules Co', 'code' => 'aimodules']);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Модульный', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Ольга', 'Рядовая', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, int $userId): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    protected function toolNames(array $tools): array
    {
        return array_map(static fn (array $tool): string => $tool['function']['name'], $tools);
    }

    /** Наполнение всех модулей: CRM, финансы, склад, проект, бухгалтерия и витрина BI. */
    protected function seedModules(): void
    {
        $client = CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'ООО Клиент Модульный',
            'kind' => 'company',
            'status' => 'active',
        ]);

        CrmDeal::create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'title' => 'Открытая сделка',
            'amount' => 15000,
            'stage' => 'negotiation',
            'probability' => 75,
        ]);

        CrmDeal::create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'title' => 'Выигранная сделка',
            'amount' => 5000,
            'stage' => 'won',
            'probability' => 100,
            'closed_at' => now()->toDateString(),
        ]);

        $financeAccount = FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Расчётный счёт',
            'kind' => 'bank',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $financeAccount->id,
            'direction' => 'income',
            'amount' => 1000,
            'occurred_at' => now()->toDateString(),
        ]);
        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $financeAccount->id,
            'direction' => 'expense',
            'amount' => 400,
            'occurred_at' => now()->toDateString(),
        ]);

        $warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'MAIN',
            'name' => 'Основной склад',
            'is_active' => true,
        ]);
        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-AI-1',
            'name' => 'Бумага А4',
            'unit' => 'шт',
            'price' => 100,
            'min_stock' => 10,
            'is_active' => true,
        ]);
        app(InventoryService::class)->move([
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => StockMovementType::In,
            'quantity' => 5,
        ]);

        $project = Project::create([
            'company_id' => $this->company->id,
            'code' => 'AI-PRJ-1',
            'name' => 'Внедрение BI',
            'status' => 'active',
            'budget' => 100000,
            'due_date' => now()->addMonth()->toDateString(),
        ]);

        Task::create([
            'company_id' => $this->company->id,
            'project_id' => $project->id,
            'title' => 'Готово',
            'number' => 'AI-T-1',
            'status' => 'completed',
            'completed_at' => now(),
            'type' => 'task',
            'priority' => 'medium',
        ]);
        Task::create([
            'company_id' => $this->company->id,
            'project_id' => $project->id,
            'title' => 'В работе',
            'number' => 'AI-T-2',
            'status' => 'in_progress',
            'type' => 'task',
            'priority' => 'medium',
        ]);

        $accounting = app(AccountingService::class);
        $accounting->ensureDefaultChart($this->company->id);
        $accounting->createEntry($this->company->id, [
            'entry_date' => now()->toDateString(),
            'description' => 'Поступление выручки',
        ], [
            ['account_id' => AccAccount::query()->where('company_id', $this->company->id)->where('code', '51')->value('id'), 'debit' => 1000, 'credit' => 0],
            ['account_id' => AccAccount::query()->where('company_id', $this->company->id)->where('code', '90.1')->value('id'), 'debit' => 0, 'credit' => 1000],
        ]);

        $month = now()->format('Y-m');
        app(BiService::class)->rebuild(
            $this->company->id,
            $month.'-01',
            now()->endOfMonth()->toDateString(),
        );
    }

    /** Новые инструменты видны только тем, у кого есть права модулей. */
    public function test_new_tools_are_permission_filtered(): void
    {
        $registry = app(AiToolRegistry::class);

        $adminNames = $this->toolNames($registry->toolsFor($this->adminEmployee));

        foreach (self::NEW_TOOLS as $tool) {
            self::assertContains($tool, $adminNames, 'super_admin должен видеть '.$tool);
        }

        $workerNames = $this->toolNames($registry->toolsFor($this->worker));

        foreach (self::NEW_TOOLS as $tool) {
            self::assertNotContains($tool, $workerNames, 'employee не должен видеть '.$tool);
        }

        // старые инструменты не задеты фильтрацией
        self::assertContains('search_tasks', $workerNames);
    }

    /** CRM-инструменты читают данные компании и возвращают ссылки. */
    public function test_crm_tools_return_company_data(): void
    {
        $this->seedModules();
        $registry = app(AiToolRegistry::class);

        $pipeline = $registry->execute($this->adminEmployee, 'crm_pipeline_summary', []);
        self::assertTrue($pipeline['ok']);
        self::assertSame(1, $pipeline['result']['open_deals']);
        self::assertSame(15000.0, $pipeline['result']['open_amount']);
        self::assertContains('won', array_column($pipeline['result']['stages'], 'stage'));
        self::assertStringContainsString('/crm/deals', $pipeline['result']['url']);

        $clients = $registry->execute($this->adminEmployee, 'crm_clients_search', ['query' => 'Модульный']);
        self::assertTrue($clients['ok']);
        self::assertSame(1, $clients['result']['count']);
        self::assertSame('ООО Клиент Модульный', $clients['result']['items'][0]['name']);
        self::assertStringContainsString('/crm/clients/', $clients['result']['items'][0]['url']);

        // нет совпадений — пусто, но без ошибки
        $empty = $registry->execute($this->adminEmployee, 'crm_clients_search', ['query' => 'нет-такого']);
        self::assertTrue($empty['ok']);
        self::assertSame(0, $empty['result']['count']);
    }

    /** Финансы и склад: сводка месяца и низкие остатки. */
    public function test_finance_and_inventory_tools(): void
    {
        $this->seedModules();
        $registry = app(AiToolRegistry::class);

        $finance = $registry->execute($this->adminEmployee, 'finance_summary', []);
        self::assertTrue($finance['ok']);
        self::assertSame(1000.0, $finance['result']['income']);
        self::assertSame(400.0, $finance['result']['expense']);
        self::assertSame(600.0, $finance['result']['profit']);
        self::assertSame(600.0, $finance['result']['balance']);

        // невалидный месяц — не ошибка, берётся текущий
        $fallback = $registry->execute($this->adminEmployee, 'finance_summary', ['month' => 'мусор']);
        self::assertTrue($fallback['ok']);
        self::assertSame(now()->format('Y-m'), $fallback['result']['month']);

        // неверный тип аргумента отклоняется
        $wrongType = $registry->execute($this->adminEmployee, 'finance_summary', ['month' => 42]);
        self::assertFalse($wrongType['ok']);
        self::assertSame('type:month', $wrongType['error']);

        $stock = $registry->execute($this->adminEmployee, 'inventory_stock_summary', []);
        self::assertTrue($stock['ok']);
        self::assertSame(1, $stock['result']['products']);
        self::assertSame(1, $stock['result']['low_stock']);
        self::assertSame('Бумага А4', $stock['result']['items'][0]['name']);
        self::assertSame(5.0, $stock['result']['items'][0]['stock']);
    }

    /** Проекты, бухгалтерия и BI: прогресс, обороты, метрики. */
    public function test_projects_accounting_and_bi_tools(): void
    {
        $this->seedModules();
        $registry = app(AiToolRegistry::class);

        $projects = $registry->execute($this->adminEmployee, 'projects_summary', []);
        self::assertTrue($projects['ok']);
        self::assertSame(1, $projects['result']['open_projects']);
        self::assertSame(50, $projects['result']['items'][0]['progress']);
        self::assertSame(100000.0, $projects['result']['items'][0]['budget']);
        self::assertStringContainsString('/projects/', $projects['result']['items'][0]['url']);

        $trial = $registry->execute($this->adminEmployee, 'accounting_trial_balance', []);
        self::assertTrue($trial['ok']);
        self::assertSame(1000.0, $trial['result']['debit']);
        self::assertSame(1000.0, $trial['result']['credit']);
        self::assertNotEmpty($trial['result']['accounts']);
        self::assertContains('51', array_column($trial['result']['accounts'], 'code'));

        $bi = $registry->execute($this->adminEmployee, 'bi_metrics_summary', []);
        self::assertTrue($bi['ok']);
        self::assertSame(now()->format('Y-m'), $bi['result']['month']);
        self::assertSame(1000.0, $bi['result']['metrics']['finance_income']);
        self::assertArrayHasKey('income_forecast', $bi['result']);
    }

    /** Без прав новые инструменты не исполняются, отказ пишется в аудит. */
    public function test_worker_is_denied_and_audit_is_written(): void
    {
        $this->seedModules();
        $registry = app(AiToolRegistry::class);

        foreach (['crm_pipeline_summary', 'finance_summary', 'inventory_stock_summary', 'projects_summary', 'accounting_trial_balance', 'bi_metrics_summary'] as $tool) {
            $result = $registry->execute($this->worker, $tool, []);
            self::assertFalse($result['ok'], 'employee не должен исполнять '.$tool);
            self::assertSame('forbidden', $result['error']);
        }

        // успешный вызов идёт в аудит
        self::assertTrue($registry->execute($this->adminEmployee, 'crm_pipeline_summary', [])['ok']);

        self::assertSame(6, AuditLog::query()->where('action', 'ai.tool.denied')->count());
        self::assertSame(1, AuditLog::query()->where('action', 'ai.tool.called')->count());
        self::assertDatabaseHas('audit_logs', [
            'action' => 'ai.tool.called',
            'object_type' => 'ai_tool',
        ]);
    }
}
