<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceAnomaly;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** DB-04/DB-06/DB-12: ограничения схемы и защита от дублей. */
class DatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    protected function seedPayroll(): array
    {
        $company = Company::create(['name' => 'DB Co', 'code' => 'dbco']);
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'DB-1',
            'first_name' => 'База',
            'last_name' => 'Данных',
            'status' => 'active',
        ]);
        $component = SalaryComponent::create([
            'company_id' => $company->id,
            'code' => 'BASE',
            'name' => 'Оклад',
            'kind' => 'earning',
            'calculation' => 'fixed',
            'rate' => 100,
            'is_active' => true,
        ]);
        $period = PayrollPeriod::create([
            'company_id' => $company->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);

        return [$period, $employee, $component];
    }

    public function test_missing_foreign_keys_null_reference_on_delete(): void
    {
        $company = Company::create(['name' => 'DB FK Co', 'code' => 'dbfk']);
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'DB-FK-1',
            'first_name' => 'Связь',
            'last_name' => 'Проверка',
            'status' => 'active',
        ]);
        $category = DocumentCategory::create(['code' => 'DBFK', 'name' => 'DB FK']);
        $template = DocumentTemplate::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Шаблон',
            'kind' => 'blade',
            'body' => 'body',
        ]);
        $document = Document::create([
            'company_id' => $company->id,
            'document_number' => 'Д-DBFK-1',
            'category_id' => $category->id,
            'title' => 'Документ',
            'status' => 'draft',
            'template_id' => $template->id,
            'author_id' => $employee->id,
        ]);

        $template->delete();
        $this->assertNull($document->fresh()->template_id);
    }

    public function test_nullable_scope_uniques_treat_null_as_zero(): void
    {
        $this->seed(RbacSeeder::class);
        $company = Company::create(['name' => 'DB NULL Co', 'code' => 'dbnull']);
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'DB-NULL-1',
            'first_name' => 'Нуль',
            'last_name' => 'Скоуп',
            'status' => 'active',
        ]);
        $roleId = Role::query()->where('slug', 'employee')->value('id');

        EmployeeRole::create(['employee_id' => $employee->id, 'role_id' => $roleId]);

        try {
            EmployeeRole::create(['employee_id' => $employee->id, 'role_id' => $roleId]);
            $this->fail('Дублирование роли с NULL-department должно быть запрещено');
        } catch (QueryException) {
            $this->assertSame(1, EmployeeRole::query()->where('employee_id', $employee->id)->count());
        }

        $attributes = [
            'employee_id' => null,
            'date' => '2026-11-01',
            'type' => 'no_show',
            'status' => 'open',
        ];
        AttendanceAnomaly::create($attributes);

        try {
            AttendanceAnomaly::create($attributes);
            $this->fail('Дублирование глобальной аномалии должно быть запрещено');
        } catch (QueryException) {
            $this->assertSame(1, AttendanceAnomaly::query()->whereNull('employee_id')->count());
        }
    }

    public function test_payroll_lines_cannot_be_duplicated(): void
    {
        [$period, $employee, $component] = $this->seedPayroll();

        $attributes = [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'component_id' => $component->id,
            'source' => 'auto',
            'quantity' => 1,
            'rate' => 100,
            'amount' => 100,
        ];
        PayrollLine::create($attributes);

        try {
            PayrollLine::create($attributes);
            $this->fail('Дублирование строки ведомости должно быть запрещено');
        } catch (QueryException) {
            $this->assertSame(1, PayrollLine::query()->count());
        }
    }

    public function test_payroll_unique_migration_refuses_existing_duplicates(): void
    {
        [$period, $employee, $component] = $this->seedPayroll();

        $migration = require database_path('migrations/2026_11_22_000006_unique_payroll_lines.php');
        $migration->down();

        $attributes = [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'component_id' => $component->id,
            'source' => 'auto',
            'quantity' => 1,
            'rate' => 100,
            'amount' => 100,
        ];
        PayrollLine::create($attributes);
        PayrollLine::create($attributes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate payroll lines');
        $migration->up();
    }
}
