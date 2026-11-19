<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AccAccount;
use App\Models\AccDocument;
use App\Models\AccEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\FinAccount;
use App\Models\FinTransaction;
use App\Models\Role;
use App\Models\User;
use App\Support\Sidebar;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Этап E4 (Accounting): гейтинг прав, план счетов, сбалансированные проводки,
 * авто-проводки из финансов, отчёты (ОПиУ/баланс/оборотка) и изоляция тенантов.
 */
class AccountingHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected FinAccount $financeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Accounting Co', 'code' => 'accountingco']);
        $this->adminUser = User::factory()->create(['email' => 'acc-admin@corpms.demo']);
        $this->adminEmployee = $this->makeEmployee($this->company, 'A-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->adminEmployee->refresh();

        $this->financeAccount = FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Расчётный счёт',
            'kind' => 'bank',
            'opening_balance' => 0,
            'is_active' => true,
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

    protected function accountByCode(string $code): AccAccount
    {
        return AccAccount::query()
            ->where('company_id', $this->company->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    /** Гейтинг: без accounting.* — 403; super_admin видит раздел в сайдбаре. */
    public function test_permission_gating(): void
    {
        $worker = $this->makeEmployee($this->company, 'W-1', 'Работник', 'Работников', 'employee');
        $workerUser = User::findOrFail($worker->user_id);

        $this->actingAs($workerUser)->get('/accounting/chart')->assertForbidden();
        $this->actingAs($workerUser)->get('/accounting/entries')->assertForbidden();
        $this->actingAs($workerUser)->get('/accounting/documents')->assertForbidden();
        $this->actingAs($workerUser)->get('/accounting/reports')->assertForbidden();
        $this->actingAs($workerUser)->post('/accounting/sync', ['month' => now()->format('Y-m')])->assertForbidden();

        $this->actingAs($this->adminUser)->get('/accounting/chart')->assertOk()->assertSee('План счетов');
        $this->actingAs($this->adminUser)->get('/accounting/entries')->assertOk();
        $this->actingAs($this->adminUser)->get('/accounting/documents')->assertOk();
        $this->actingAs($this->adminUser)->get('/accounting/reports')->assertOk();

        $routes = Sidebar::routes($this->adminUser);
        $this->assertContains('accounting.chart.index', $routes);
        $this->assertContains('accounting.entries.index', $routes);

        $workerRoutes = Sidebar::routes($workerUser);
        $this->assertNotContains('accounting.chart.index', $workerRoutes);
    }

    /** Типовой план счетов идемпотентен; код счёта уникален в компании. */
    public function test_default_chart_and_custom_account(): void
    {
        $this->post('/accounting/chart/default')->assertSessionHasNoErrors();

        $this->assertSame(12, AccAccount::query()->where('company_id', $this->company->id)->count());
        $this->assertDatabaseHas('acc_accounts', ['company_id' => $this->company->id, 'code' => '51', 'kind' => 'asset']);
        $this->assertDatabaseHas('acc_accounts', ['company_id' => $this->company->id, 'code' => '90.1', 'kind' => 'income']);

        // повторный вызов не дублирует
        $this->post('/accounting/chart/default')->assertSessionHasNoErrors();
        $this->assertSame(12, AccAccount::query()->where('company_id', $this->company->id)->count());

        // свой счёт и защита от дубля кода
        $this->post('/accounting/chart', ['code' => '76', 'name' => 'Расчёты с разными дебиторами', 'kind' => 'asset'])
            ->assertSessionHasNoErrors();
        $this->assertSame(13, AccAccount::query()->where('company_id', $this->company->id)->count());

        $this->post('/accounting/chart', ['code' => '76', 'name' => 'Дубль', 'kind' => 'asset'])
            ->assertSessionHasErrors('code');

        $this->get('/accounting/chart')->assertOk()->assertSee('Расчётные счета')->assertSee('76');
    }

    public function test_simple_entry_uses_one_amount_for_both_accounts(): void
    {
        $this->post('/accounting/chart/default')->assertSessionHasNoErrors();
        $debit = $this->accountByCode('51');
        $credit = $this->accountByCode('90.1');
        $url = '/accounting/entries';
        $payload = [
            'entry_mode' => 'simple',
            'entry_date' => now()->toDateString(),
            'debit_account_id' => $debit->id,
            'credit_account_id' => $credit->id,
            'amount' => '1250.50',
        ];

        $this->post($url, array_replace($payload, ['amount' => '1250.505']))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('acc_entries', 0);
        $this->post($url, $payload)->assertSessionHasNoErrors();

        $entry = AccEntry::query()->sole();
        $this->assertCount(2, $entry->lines);
        $this->assertDatabaseHas('acc_entry_lines', ['entry_id' => $entry->id, 'account_id' => $debit->id, 'debit' => 1250.5, 'credit' => 0]);
        $this->assertDatabaseHas('acc_entry_lines', ['entry_id' => $entry->id, 'account_id' => $credit->id, 'debit' => 0, 'credit' => 1250.5]);
    }

    /** Ручная проводка: баланс дебета и кредита обязателен. */
    public function test_manual_entry_requires_balance(): void
    {
        $this->post('/accounting/chart/default');

        $debit = $this->accountByCode('51');
        $credit = $this->accountByCode('90.1');

        $this->post('/accounting/entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'Поступление выручки',
            'lines' => [
                ['account_id' => $debit->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $credit->id, 'debit' => 0, 'credit' => 1000],
            ],
        ])->assertSessionHasNoErrors();

        $entry = AccEntry::query()->firstOrFail();
        $this->assertSame('manual', $entry->source->value);
        $this->assertSame(1000.0, $entry->debitTotal());
        $this->assertSame(1000.0, $entry->creditTotal());
        $this->assertCount(2, $entry->lines);

        $this->get('/accounting/entries/'.$entry->id)->assertOk()->assertSee('Поступление выручки')->assertSee('сбалансирована');

        // несбалансированная отклоняется
        $this->post('/accounting/entries', [
            'entry_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $debit->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $credit->id, 'debit' => 0, 'credit' => 900],
            ],
        ])->assertSessionHasErrors('lines');

        // обе стороны в одной строке — ошибка
        $this->post('/accounting/entries', [
            'entry_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $debit->id, 'debit' => 100, 'credit' => 100],
                ['account_id' => $credit->id, 'debit' => 0, 'credit' => 0],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(1, AccEntry::query()->count());
    }

    /** Синк из финансов: идемпотентные проводки + отчёты сходятся. */
    public function test_finance_sync_and_reports(): void
    {
        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $this->financeAccount->id,
            'direction' => 'income',
            'amount' => 5000,
            'occurred_at' => now()->toDateString(),
            'description' => 'Выручка за услуги',
        ]);
        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $this->financeAccount->id,
            'direction' => 'expense',
            'amount' => 2000,
            'occurred_at' => now()->toDateString(),
            'description' => 'Аренда офиса',
        ]);

        $month = now()->format('Y-m');

        $this->post('/accounting/sync', ['month' => $month])->assertSessionHasNoErrors();
        $this->assertSame(2, AccEntry::query()->count());

        // доход: Дт 51 — Кт 90.1
        $incomeEntry = AccEntry::query()
            ->whereHas('lines.account', fn ($q) => $q->where('code', '90.1'))
            ->firstOrFail();
        $this->assertSame('finance', $incomeEntry->source->value);
        $this->assertSame(5000.0, $incomeEntry->debitTotal());

        // расход: Дт 91.2 — Кт 51
        $expenseEntry = AccEntry::query()
            ->whereHas('lines.account', fn ($q) => $q->where('code', '91.2'))
            ->firstOrFail();
        $this->assertSame(2000.0, $expenseEntry->creditTotal());

        // повторный синк не создаёт дублей
        $this->post('/accounting/sync', ['month' => $month])->assertSessionHasNoErrors();
        $this->assertSame(2, AccEntry::query()->count());

        // отчёты: ОПиУ 5000 − 2000 = 3000, баланс сходится, оборотка равна
        $this->get('/accounting/reports?month='.$month)
            ->assertOk()
            ->assertSee('5 000,00')
            ->assertSee('2 000,00')
            ->assertSee('3 000,00')
            ->assertSee('сходится');

        $this->get('/accounting/entries?month='.$month)
            ->assertOk()
            ->assertSee('Выручка за услуги')
            ->assertSee('Аренда офиса')
            ->assertSee('Из финансов');
    }

    /** Первичные документы: создание, связь с проводкой, уникальность номера. */
    public function test_documents_and_entry_link(): void
    {
        $this->post('/accounting/chart/default');
        $this->post('/accounting/entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'Оплата поставщику',
            'lines' => [
                ['account_id' => $this->accountByCode('60')->id, 'debit' => 700, 'credit' => 0],
                ['account_id' => $this->accountByCode('51')->id, 'debit' => 0, 'credit' => 700],
            ],
        ])->assertSessionHasNoErrors();

        $entry = AccEntry::query()->firstOrFail();

        $this->post('/accounting/documents', [
            'number' => 'ПП-1',
            'kind' => 'payment_order',
            'document_date' => now()->toDateString(),
            'amount' => 700,
            'counterparty' => 'ООО Поставщик',
            'entry_id' => $entry->id,
        ])->assertSessionHasNoErrors();

        $document = AccDocument::query()->firstOrFail();
        $this->assertSame($entry->id, (int) $document->entry_id);

        // дубль номера
        $this->post('/accounting/documents', [
            'number' => 'ПП-1',
            'kind' => 'payment_order',
            'document_date' => now()->toDateString(),
        ])->assertSessionHasErrors('number');

        $this->get('/accounting/documents')->assertOk()->assertSee('ПП-1')->assertSee('ООО Поставщик');

        $this->delete('/accounting/documents/'.$document->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('acc_documents', 0);
        $this->assertDatabaseCount('acc_entries', 1);
    }

    /** Изоляция: чужие счета, проводки и документы — 403, ссылки из тела — 403. */
    public function test_tenant_isolation(): void
    {
        $otherCompany = Company::create(['name' => 'Other Accounting', 'code' => 'otheracc']);
        $foreignAccount = AccAccount::create([
            'company_id' => $otherCompany->id,
            'code' => '51',
            'name' => 'Чужой счёт',
            'kind' => 'asset',
            'is_active' => true,
        ]);
        $foreignEntry = AccEntry::create([
            'company_id' => $otherCompany->id,
            'entry_date' => now()->toDateString(),
            'description' => 'Чужая проводка',
            'source' => 'manual',
        ]);
        $foreignDocument = AccDocument::create([
            'company_id' => $otherCompany->id,
            'number' => 'FOREIGN-DOC',
            'kind' => 'other',
            'document_date' => now()->toDateString(),
            'amount' => 1,
        ]);

        $this->get('/accounting/entries/'.$foreignEntry->id)->assertForbidden();
        $this->post('/accounting/chart/'.$foreignAccount->id.'/toggle')->assertForbidden();
        $this->delete('/accounting/documents/'.$foreignDocument->id)->assertForbidden();

        // ссылки из тела: чужой счёт в проводке и чужое основание в документе
        $this->post('/accounting/chart/default');
        $this->post('/accounting/entries', [
            'entry_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $foreignAccount->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->accountByCode('51')->id, 'debit' => 0, 'credit' => 100],
            ],
        ])->assertForbidden();

        $this->post('/accounting/documents', [
            'number' => 'DOC-X',
            'kind' => 'other',
            'document_date' => now()->toDateString(),
            'entry_id' => $foreignEntry->id,
        ])->assertForbidden();

        // списки не показывают чужие записи
        $this->get('/accounting/chart')->assertOk()->assertDontSee('Чужой счёт');
        $this->get('/accounting/entries')->assertOk()->assertDontSee('Чужая проводка');
        $this->get('/accounting/documents')->assertOk()->assertDontSee('FOREIGN-DOC');

        $this->assertSame(0, AccEntry::query()->where('company_id', $this->company->id)->count());
    }
}
