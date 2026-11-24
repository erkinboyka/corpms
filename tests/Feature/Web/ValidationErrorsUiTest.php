<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeSalary;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S9 (спринт по аудиту): ошибки валидации видны без закрытия формы,
 * введённые значения сохраняются (old()), confirm() на финансовых действиях.
 */
class ValidationErrorsUiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Test Co', 'code' => 'testco']);
        $this->adminUser = User::factory()->create([
            'email' => 'admin@corpms.demo',
            'password' => 'password',
        ]);
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    /** Ошибка валидации модалки: скрипт переоткрытия + сохранение ввода (old()). */
    public function test_validation_error_renders_modal_reopen_script_and_old_input(): void
    {
        $this->from('/departments')
            ->post('/departments', ['code' => 'SALES'])
            ->assertRedirect('/departments')
            ->assertSessionHasErrors('name');

        // следующий запрос наследует сессию POST-редиректа (как в браузере)
        $this->followingRedirects()
            ->from('/departments')
            ->post('/departments', ['code' => 'SALES'])
            ->assertOk()
            ->assertSee('data-crm-validation-errors')
            ->assertSee('var errors = {"name":', false)
            ->assertSee('value="SALES"', false);
    }

    /** Метка скрипта не попадает на страницы без ошибок валидации. */
    public function test_page_without_errors_has_no_reopen_script(): void
    {
        $this->get('/departments')
            ->assertOk()
            ->assertDontSee('data-crm-validation-errors', false);
    }

    /** Финансовые действия ведомости требуют подтверждения («Согласовать» → «Выплатить»). */
    public function test_payroll_payment_requires_confirmation(): void
    {
        $employee = $this->employee('E-1', 'Иван', 'Сидоров');
        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'base_amount' => 5000,
            'currency' => 'TJS',
            'effective_from' => '2026-01-01',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-05',
            'worked_minutes' => 5040,
            'overtime_minutes' => 0,
            'scheduled_minutes' => 5040,
            'status' => 'present',
            'source' => 'manual',
        ]);
        SalaryComponent::create(['company_id' => $this->company->id, 'code' => 'SALARY', 'name' => 'Оклад', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 0]);
        $period = PayrollPeriod::create([
            'company_id' => $this->company->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'draft',
        ]);

        // calculated: кнопка «Согласовать» с confirm()
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();
        $this->get('/payroll/periods/'.$period->id)
            ->assertOk()
            ->assertSee('data-confirm="Согласовать ведомость', false);

        // approved: кнопка «Выплатить» с confirm()
        $this->post('/payroll/periods/'.$period->id.'/approve')->assertRedirect();
        $this->get('/payroll/periods/'.$period->id)
            ->assertOk()
            ->assertSee('data-confirm="Выплатить ведомость', false);
    }

    /** Архивация документа требует подтверждения. */
    public function test_document_archive_requires_confirmation(): void
    {
        $category = DocumentCategory::create(['code' => 'LET', 'name' => 'Служебные письма']);
        Document::create([
            'company_id' => $this->company->id,
            'author_id' => $this->adminEmployee->id,
            'category_id' => $category->id,
            'title' => 'Договор поставки',
            'document_number' => 'Д-001',
            'status' => 'registered',
            'confidentiality' => 'internal',
        ]);

        $document = Document::query()->where('title', 'Договор поставки')->sole();
        $this->get('/documents/'.$document->id)
            ->assertOk()
            ->assertSee('data-confirm="Отправить документ', false);
    }

    /** Сброс пароля пользователю требует подтверждения. */
    public function test_user_password_reset_requires_confirmation(): void
    {
        $this->get('/users')
            ->assertOk()
            ->assertSee('data-confirm="Сбросить пароль для', false);
    }
}
