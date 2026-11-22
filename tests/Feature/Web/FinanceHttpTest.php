<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Ai\Services\AiToolRegistry;
use App\Domains\Finance\Services\FinanceService;
use App\Models\Company;
use App\Models\CrmClient;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\FinAccount;
use App\Models\FinBudget;
use App\Models\FinCategory;
use App\Models\FinInvoice;
use App\Models\FinTransaction;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\FinInvoiceStatus;
use App\Support\Sidebar;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Этап E3 (Finance): гейтинг прав, баланс по журналу, счёт → оплата → paid,
 * бюджеты план/факт и межтенантная изоляция.
 */
class FinanceHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected FinAccount $account;

    protected FinCategory $expenseCategory;

    protected FinCategory $incomeCategory;

    protected CrmClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Finance Co', 'code' => 'financeco']);
        $this->adminUser = User::factory()->create(['email' => 'fin-admin@corpms.demo']);
        $this->adminEmployee = $this->makeEmployee($this->company, 'F-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->adminEmployee->refresh();

        $this->account = FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Расчётный счёт',
            'kind' => 'bank',
            'currency' => 'RUB',
            'opening_balance' => 1000,
            'is_active' => true,
        ]);

        $this->expenseCategory = FinCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Аренда',
            'direction' => 'expense',
            'is_active' => true,
        ]);

        $this->incomeCategory = FinCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Выручка',
            'direction' => 'income',
            'is_active' => true,
        ]);

        $this->client = CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'ООО Плательщик',
            'kind' => 'company',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function makeEmployee(Company $company, string $no, string $first, string $last, ?string $roleSlug = null): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        if ($roleSlug !== null) {
            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            ]);
        }

        return $employee;
    }

    /** Гейтинг: без finance.* — 403; super_admin видит раздел в сайдбаре. */
    public function test_permission_gating(): void
    {
        $worker = $this->makeEmployee($this->company, 'W-1', 'Работник', 'Работников', 'employee');
        $workerUser = User::findOrFail($worker->user_id);

        $this->actingAs($workerUser)->get('/finance/accounts')->assertForbidden();
        $this->actingAs($workerUser)->get('/finance/invoices')->assertForbidden();
        $this->actingAs($workerUser)->get('/finance/transactions')->assertForbidden();
        $this->actingAs($workerUser)->get('/finance/budgets')->assertForbidden();
        $this->actingAs($workerUser)->post('/finance/transactions', [
            'account_id' => $this->account->id,
            'direction' => 'income',
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($this->adminUser)->get('/finance/accounts')->assertOk()->assertSee('Расчётный счёт');
        $this->actingAs($this->adminUser)->get('/finance/invoices')->assertOk();
        $this->actingAs($this->adminUser)->get('/finance/transactions')->assertOk();
        $this->actingAs($this->adminUser)->get('/finance/budgets')->assertOk();
        $this->actingAs($this->adminUser)->get('/finance/categories')->assertOk()->assertSee('Аренда');

        $routes = Sidebar::routes($this->adminUser);
        $this->assertContains('finance.accounts.index', $routes);
        $this->assertContains('finance.invoices.index', $routes);

        $workerRoutes = Sidebar::routes($workerUser);
        $this->assertNotContains('finance.accounts.index', $workerRoutes);
    }

    /** Баланс = opening_balance + доходы − расходы; направление статьи проверяется. */
    public function test_mixed_currencies_are_separate_in_metrics_and_http_summaries(): void
    {
        $usd = FinAccount::create(['company_id' => $this->company->id, 'name' => 'Dollar bank',
            'kind' => 'bank', 'currency' => 'USD', 'opening_balance' => 100, 'is_active' => true]);
        foreach ([[$this->account, 'income', 500], [$usd, 'income', 20], [$usd, 'expense', 5]] as [$account, $direction, $amount]) {
            FinTransaction::create(['company_id' => $this->company->id, 'account_id' => $account->id,
                'direction' => $direction, 'amount' => $amount, 'occurred_at' => now()->toDateString(),
                'source' => 'manual', 'created_by' => $this->adminUser->id]);
        }
        $metrics = app(FinanceService::class)->metrics($this->company->id);
        $this->assertNull($metrics['balance']);
        $this->assertNull($metrics['income']);
        $this->assertNull($metrics['unpaid']);
        $this->assertNull($metrics['budget_planned']);
        $this->assertSame(1500.0, $metrics['by_currency']['RUB']['balance']);
        $this->assertSame(115.0, $metrics['by_currency']['USD']['balance']);
        $this->assertSame(15.0, $metrics['by_currency']['USD']['profit']);
        $ai = app(AiToolRegistry::class)->execute($this->adminEmployee, 'finance_summary', []);
        $this->assertTrue($ai['ok']);
        $this->assertNull($ai['result']['income']);
        $this->assertSame(20.0, $ai['result']['by_currency']['USD']['income']);
        foreach (['accounts', 'transactions', 'invoices', 'budgets'] as $page) {
            $this->get('/finance/'.$page)->assertOk()->assertSee('USD')->assertSee('RUB');
        }
    }

    /** TEST-11: валюты считаются независимо — матрица через data provider. */
    #[DataProvider('currencyMatrix')]
    public function test_currency_balance_is_isolated(string $currency, float $opening): void
    {
        FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Счёт '.$currency,
            'kind' => 'bank',
            'currency' => $currency,
            'opening_balance' => $opening,
            'is_active' => true,
        ]);

        $metrics = app(FinanceService::class)->metrics($this->company->id);

        self::assertSame($opening, $metrics['by_currency'][$currency]['balance']);
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function currencyMatrix(): array
    {
        // В setUp уже есть RUB-счёт, поэтому матрица использует другие валюты.
        return [
            'dollar' => ['USD', 100.0],
            'euro' => ['EUR', 250.0],
            'somoni' => ['TJS', 50.0],
            'tenge' => ['KZT', 500.0],
        ];
    }

    public function test_mixed_currency_budget_does_not_report_a_false_usage_percentage(): void
    {
        $usd = FinAccount::create(['company_id' => $this->company->id, 'name' => 'USD',
            'kind' => 'bank', 'currency' => 'USD', 'opening_balance' => 0, 'is_active' => true]);
        FinBudget::create(['company_id' => $this->company->id, 'period' => now()->startOfMonth()->toDateString(),
            'direction' => 'expense', 'planned_amount' => 100]);
        FinTransaction::create(['company_id' => $this->company->id, 'account_id' => $usd->id,
            'direction' => 'expense', 'amount' => 25, 'occurred_at' => now()->toDateString(),
            'source' => 'manual', 'created_by' => $this->adminUser->id]);
        $budget = app(FinanceService::class)->budgetsWithActual($this->company->id, now()->format('Y-m'))->sole();
        $this->assertNull($budget->actual);
        $this->assertNull($budget->usage_percent);
        $this->assertSame(['USD' => 25.0], $budget->actual_by_currency);
        $this->get('/finance/budgets')->assertOk()->assertSee('Плану не назначена валюта');
    }

    public function test_transactions_change_balance(): void
    {
        $this->post('/finance/transactions', [
            'account_id' => $this->account->id,
            'category_id' => $this->incomeCategory->id,
            'direction' => 'income',
            'amount' => 500,
            'occurred_at' => now()->toDateString(),
            'description' => 'Оплата услуг',
        ])->assertSessionHasNoErrors();

        $this->post('/finance/transactions', [
            'account_id' => $this->account->id,
            'category_id' => $this->expenseCategory->id,
            'direction' => 'expense',
            'amount' => 200,
            'occurred_at' => now()->toDateString(),
            'description' => 'Аренда офиса',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1300.0, app(FinanceService::class)->balance($this->account->fresh()));
        $this->assertDatabaseCount('fin_transactions', 2);

        $this->get('/finance/transactions')->assertOk()->assertSee('Аренда офиса')->assertSee('Оплата услуг');
        $this->get('/finance/accounts')->assertOk()->assertSee('1 300,00');

        // статья другого направления — ошибка
        $this->post('/finance/transactions', [
            'account_id' => $this->account->id,
            'category_id' => $this->incomeCategory->id,
            'direction' => 'expense',
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertSessionHasErrors('category_id');

        // нулевая сумма отклоняется
        $this->post('/finance/transactions', [
            'account_id' => $this->account->id,
            'direction' => 'income',
            'amount' => 0,
            'occurred_at' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->assertSame(1300.0, app(FinanceService::class)->balance($this->account->fresh()));
    }

    /** Счёт: черновик не оплачивается; выставленный принимает частичную оплату до paid. */
    public function test_invoice_payment_lifecycle(): void
    {
        $this->post('/finance/invoices', [
            'number' => 'INV-1',
            'client_id' => $this->client->id,
            'kind' => 'invoice',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'amount' => 1000,
            'vat_rate' => 20,
        ])->assertSessionHasNoErrors();

        $invoice = FinInvoice::query()->where('number', 'INV-1')->firstOrFail();
        $this->assertSame(FinInvoiceStatus::Draft, $invoice->status);

        // черновик оплатить нельзя
        $this->post('/finance/invoices/'.$invoice->id.'/payments', [
            'account_id' => $this->account->id,
            'amount' => 300,
            'paid_at' => now()->toDateString(),
            'method' => 'bank_transfer',
        ])->assertSessionHasErrors('amount');

        $this->post('/finance/invoices/'.$invoice->id.'/issue')->assertSessionHasNoErrors();
        $this->assertSame(FinInvoiceStatus::Issued, $invoice->fresh()->status);

        // частичная оплата: статус issued, в журнале приход
        $this->post('/finance/invoices/'.$invoice->id.'/payments', [
            'account_id' => $this->account->id,
            'category_id' => $this->incomeCategory->id,
            'amount' => 300,
            'paid_at' => now()->toDateString(),
            'method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(FinInvoiceStatus::Issued, $invoice->status);
        $this->assertSame(700.0, $invoice->remainingAmount());
        $this->assertSame(300.0, $invoice->paidAmount());
        $this->assertDatabaseHas('fin_transactions', ['amount' => 300, 'source' => 'invoice_payment']);

        // переплата отклоняется
        $this->post('/finance/invoices/'.$invoice->id.'/payments', [
            'account_id' => $this->account->id,
            'amount' => 800,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertSessionHasErrors('amount');

        // доплата остатка закрывает счёт
        $this->post('/finance/invoices/'.$invoice->id.'/payments', [
            'account_id' => $this->account->id,
            'amount' => 700,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(FinInvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0.0, $invoice->remainingAmount());
        $this->assertSame(1000.0, $invoice->paidAmount());
        $this->assertSame(2000.0, app(FinanceService::class)->balance($this->account->fresh()));

        // оплаченный счёт не отменяется и не платится повторно
        $this->post('/finance/invoices/'.$invoice->id.'/cancel')->assertSessionHasErrors('status');
        $this->post('/finance/invoices/'.$invoice->id.'/payments', [
            'account_id' => $this->account->id,
            'amount' => 1,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->get('/finance/invoices/'.$invoice->id)->assertOk()->assertSee('Оплачен');
    }

    /** Итоги месяца: доходы/расходы/прибыль считаются по журналу. */
    public function test_month_metrics(): void
    {
        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'category_id' => $this->incomeCategory->id,
            'direction' => 'income',
            'amount' => 5000,
            'occurred_at' => now()->toDateString(),
        ]);
        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'category_id' => $this->expenseCategory->id,
            'direction' => 'expense',
            'amount' => 2000,
            'occurred_at' => now()->toDateString(),
        ]);

        $metrics = app(FinanceService::class)->metrics($this->company->id, now()->format('Y-m'));

        $this->assertSame(5000.0, $metrics['income']);
        $this->assertSame(2000.0, $metrics['expense']);
        $this->assertSame(3000.0, $metrics['profit']);
        $this->assertSame(4000.0, $metrics['balance']);

        $this->get('/finance/accounts')->assertOk()->assertSee('4 000,00');
    }

    /** Бюджет: upsert по месяцу/статье, факт считается по операциям. */
    public function test_budget_plan_and_actual(): void
    {
        $month = now()->format('Y-m');

        $this->post('/finance/budgets', [
            'month' => $month,
            'direction' => 'expense',
            'category_id' => $this->expenseCategory->id,
            'planned_amount' => 1000,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, FinBudget::query()->count());

        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'category_id' => $this->expenseCategory->id,
            'direction' => 'expense',
            'amount' => 400,
            'occurred_at' => now()->toDateString(),
        ]);

        $this->get('/finance/budgets?month='.$month)->assertOk()->assertSee('Аренда')->assertSee('40%');

        // повторное сохранение обновляет план, а не создаёт дубль
        $this->post('/finance/budgets', [
            'month' => $month,
            'direction' => 'expense',
            'category_id' => $this->expenseCategory->id,
            'planned_amount' => 1500,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, FinBudget::query()->count());
        $this->assertSame(1500.0, (float) FinBudget::query()->firstOrFail()->planned_amount);

        // статья другого направления в бюджет не проходит
        $this->post('/finance/budgets', [
            'month' => $month,
            'direction' => 'expense',
            'category_id' => $this->incomeCategory->id,
            'planned_amount' => 100,
        ])->assertSessionHasErrors('category_id');
    }

    /** Изоляция: чужие счета/статьи/счета-фактуры — 403, ссылки из тела — 403. */
    public function test_tenant_isolation(): void
    {
        $otherCompany = Company::create(['name' => 'Other Finance', 'code' => 'otherfin']);
        $foreignAccount = FinAccount::create([
            'company_id' => $otherCompany->id,
            'name' => 'Чужой счёт',
            'kind' => 'bank',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $foreignCategory = FinCategory::create([
            'company_id' => $otherCompany->id,
            'name' => 'Чужая статья',
            'direction' => 'expense',
            'is_active' => true,
        ]);
        $foreignClient = CrmClient::create([
            'company_id' => $otherCompany->id,
            'name' => 'Чужой клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);
        $foreignInvoice = FinInvoice::create([
            'company_id' => $otherCompany->id,
            'client_id' => $foreignClient->id,
            'number' => 'FOREIGN-1',
            'kind' => 'invoice',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'amount' => 100,
        ]);

        // прямой доступ по ID
        $this->post('/finance/accounts/'.$foreignAccount->id.'/toggle')->assertForbidden();
        $this->post('/finance/categories/'.$foreignCategory->id.'/toggle')->assertForbidden();
        $this->get('/finance/invoices/'.$foreignInvoice->id)->assertForbidden();
        $this->post('/finance/invoices/'.$foreignInvoice->id.'/issue')->assertForbidden();
        $this->post('/finance/invoices/'.$foreignInvoice->id.'/cancel')->assertForbidden();

        // ссылки из тела
        $this->post('/finance/transactions', [
            'account_id' => $foreignAccount->id,
            'direction' => 'income',
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->post('/finance/transactions', [
            'account_id' => $this->account->id,
            'category_id' => $foreignCategory->id,
            'direction' => 'expense',
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->post('/finance/invoices', [
            'number' => 'INV-X',
            'client_id' => $foreignClient->id,
            'kind' => 'invoice',
            'issue_date' => now()->toDateString(),
            'amount' => 10,
        ])->assertForbidden();

        // списки не показывают чужие записи
        $this->get('/finance/accounts')->assertOk()->assertDontSee('Чужой счёт');
        $this->get('/finance/categories')->assertOk()->assertDontSee('Чужая статья');
        $this->get('/finance/invoices?status=all')->assertOk()->assertDontSee('FOREIGN-1');

        $this->assertDatabaseCount('fin_transactions', 0);
    }
}
