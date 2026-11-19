<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Ai\Jobs\RetryAiAnswer;
use App\Domains\Attendance\Services\AttendanceService;
use App\Domains\Biometric\Services\BiometricService;
use App\Domains\Crm\Services\CrmService;
use App\Domains\Finance\Services\FinanceService;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Payroll\Services\PayrollCalculationService;
use App\Models\AiConversation;
use App\Models\Company;
use App\Models\CrmLead;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\EmployeeSalary;
use App\Models\FinAccount;
use App\Models\FinInvoice;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Shared\Enums\PermissionScope;
use App\Shared\Enums\StockMovementType;
use App\Shared\Permissions\PermissionMatrix;
use App\Support\PermissionService;
use App\Support\SettingsService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class HardeningRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->company = Company::create(['name' => 'Hardening A', 'code' => 'hard-a']);
        $this->foreign = Company::create(['name' => 'Hardening B', 'code' => 'hard-b']);
    }

    private function employee(string $role = 'employee', ?Company $company = null, ?Department $department = null): Employee
    {
        $user = User::factory()->create(['password' => 'correct-password-123']);
        $employee = Employee::create(['company_id' => ($company ?? $this->company)->id, 'user_id' => $user->id,
            'department_id' => $department?->id, 'employee_no' => 'H-'.$user->id,
            'first_name' => 'Test', 'last_name' => 'Employee'.$user->id, 'status' => 'active']);
        $employee->roles()->attach(Role::where('slug', $role)->value('id'));

        return $employee;
    }

    public function test_multiple_roles_choose_company_and_override_can_expand_own(): void
    {
        $employee = $this->employee();
        $employee->roles()->attach(Role::where('slug', 'company_director')->value('id'));
        $this->assertSame(PermissionScope::Company, (new PermissionService)->can($employee->user, 'tasks.view'));
        $other = $this->employee();
        EmployeePermissionOverride::create(['employee_id' => $other->id,
            'permission_id' => DB::table('permissions')->where('slug', 'tasks.view')->value('id'), 'scope' => 'company']);
        $this->assertSame(PermissionScope::Company, (new PermissionService)->can($other->user, 'tasks.view'));
    }

    public function test_department_director_cannot_read_other_department_card_or_subordinates(): void
    {
        $a = Department::create(['company_id' => $this->company->id, 'name' => 'A', 'code' => 'A']);
        $b = Department::create(['company_id' => $this->company->id, 'name' => 'B', 'code' => 'B']);
        $director = $this->employee('department_director', department: $a);
        $allowed = $this->employee(department: $a);
        $denied = $this->employee(department: $b);
        $this->actingAs($director->user)->get('/employees/'.$allowed->id)->assertOk();
        $this->get('/employees/'.$denied->id)->assertForbidden();
        $this->get('/employees/'.$denied->id.'/subordinates')->assertForbidden();
    }

    public function test_department_without_department_does_not_share_null_department_peers(): void
    {
        $director = $this->employee('department_director');
        $peer = $this->employee();
        $this->actingAs($director->user)->get('/employees/'.$peer->id)->assertForbidden();
        $this->get('/employees')->assertOk()->assertDontSee($peer->last_name);
    }

    public function test_dismissed_and_inactive_users_cannot_login(): void
    {
        foreach (['dismissed', 'inactive'] as $status) {
            $employee = $this->employee('super_admin');
            $employee->update(['status' => $status]);
            $this->post('/login', ['email' => $employee->user->email, 'password' => 'correct-password-123'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertFalse((new PermissionService)->can($employee->user, 'tasks.view'));
        }
    }

    public function test_existing_session_is_rejected_after_employee_is_disabled(): void
    {
        $employee = $this->employee('super_admin');
        $this->actingAs($employee->user)->get('/dashboard')->assertOk();
        $employee->update(['status' => 'inactive']);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_old_login_stays_revoked_after_employee_is_reactivated(): void
    {
        $employee = $this->employee('super_admin');
        $this->post('/login', ['email' => $employee->user->email, 'password' => 'correct-password-123'])->assertSessionHasNoErrors();
        $employee->update(['status' => 'inactive']);
        $employee->update(['status' => 'active']);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_account_candidates_and_employee_forms_do_not_expose_other_tenants(): void
    {
        $admin = $this->employee('super_admin');
        $foreign = $this->employee(company: $this->foreign);
        $foreign->update(['user_id' => null]);
        Department::create(['company_id' => $this->foreign->id, 'name' => 'FOREIGN-DEPARTMENT-SECRET', 'code' => 'SECRET']);
        $this->actingAs($admin->user)->get('/users')->assertOk()->assertDontSee($foreign->last_name);
        $this->get('/employees/'.$admin->id)->assertOk()->assertDontSee('FOREIGN-DEPARTMENT-SECRET');
    }

    public function test_creating_account_for_already_linked_employee_creates_no_orphan(): void
    {
        $admin = $this->employee('super_admin');
        $linked = $this->employee();
        $count = User::count();
        $this->actingAs($admin->user)->post('/users', ['name' => 'Duplicate', 'email' => 'duplicate@audit.test', 'employee_id' => $linked->id])
            ->assertSessionHasErrors('employee_id');
        $this->assertSame($count, User::count());
    }

    public function test_dismissal_records_date_for_final_payroll(): void
    {
        $admin = $this->employee('super_admin');
        $employee = $this->employee();
        $this->actingAs($admin->user)->patch('/employees/'.$employee->id, ['first_name' => 'Test', 'last_name' => 'Employee',
            'employee_no' => $employee->employee_no, 'status' => 'dismissed'])->assertSessionHasNoErrors();
        $this->assertSame(today()->toDateString(), $employee->fresh()->fired_at->toDateString());
    }

    private function order(): PurchaseOrder
    {
        $warehouse = Warehouse::create(['company_id' => $this->company->id, 'name' => 'Warehouse', 'code' => 'W']);
        $product = Product::create(['company_id' => $this->company->id, 'name' => 'Product', 'sku' => 'P']);
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Supplier']);
        $order = PurchaseOrder::create(['company_id' => $this->company->id, 'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id, 'number' => 'H-ORDER', 'status' => 'ordered', 'order_date' => today()]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 1]);

        return $order;
    }

    public function test_stale_purchase_cannot_be_received_twice(): void
    {
        $order = $this->order();
        $stale = $order->fresh();
        $service = app(InventoryService::class);
        $service->receive($order);
        try {
            $service->receive($stale);
            $this->fail('Duplicate receipt accepted');
        } catch (LogicException) {
            $this->assertEquals(10, $service->stock($this->company->id, $order->warehouse_id, $order->items->first()->product_id));
            $this->assertDatabaseCount('stock_movements', 1);
        }
    }

    public function test_stale_crm_lead_cannot_create_duplicate_client_and_deal(): void
    {
        $actor = $this->employee('super_admin');
        $lead = CrmLead::create(['company_id' => $this->company->id, 'title' => 'Conversion test', 'created_by' => $actor->user_id]);
        $stale = $lead->fresh();
        $service = app(CrmService::class);
        $service->convertLead($lead, $actor->id);
        try {
            $service->convertLead($stale, $actor->id);
            $this->fail('Duplicate lead conversion accepted');
        } catch (LogicException) {
            $this->assertDatabaseCount('crm_clients', 1);
            $this->assertDatabaseCount('crm_deals', 1);
        }
    }

    public function test_stock_service_rejects_foreign_warehouse(): void
    {
        $order = $this->order();
        $warehouse = Warehouse::create(['company_id' => $this->foreign->id, 'name' => 'Foreign', 'code' => 'F']);
        $this->expectException(LogicException::class);
        app(InventoryService::class)->move(['company_id' => $this->company->id, 'warehouse_id' => $warehouse->id,
            'product_id' => $order->items->first()->product_id, 'type' => StockMovementType::In, 'quantity' => 1]);
    }

    public function test_payment_and_cancellation_recheck_current_invoice_state(): void
    {
        $account = FinAccount::create(['company_id' => $this->company->id, 'name' => 'Bank', 'kind' => 'bank']);
        $invoice = FinInvoice::create(['company_id' => $this->company->id, 'number' => 'H-INV', 'kind' => 'invoice',
            'status' => 'issued', 'issue_date' => today(), 'amount' => 100]);
        $stale = $invoice->fresh();
        $service = app(FinanceService::class);
        $service->registerPayment($invoice, ['account_id' => $account->id, 'amount' => 100, 'method' => 'bank_transfer']);
        foreach (['pay', 'cancel'] as $operation) {
            try {
                $operation === 'pay'
                    ? $service->registerPayment($stale, ['account_id' => $account->id, 'amount' => 1, 'method' => 'bank_transfer'])
                    : $service->cancel($stale);
                $this->fail('Closed invoice was changed');
            } catch (LogicException) {
                $this->assertEquals(100, $invoice->paidAmount());
                $this->assertSame('paid', $invoice->fresh()->status->value);
            }
        }
        $this->assertDatabaseCount('fin_payments', 1);
    }

    public function test_only_own_and_global_days_off_reduce_paid_leave_without_duplicate_days(): void
    {
        $employee = $this->employee();
        $leave = new LeaveRequest(['from_date' => '2026-11-19', 'to_date' => '2026-11-19']);
        $method = new \ReflectionMethod(PayrollCalculationService::class, 'paidLeaveHours');
        $service = app(PayrollCalculationService::class);
        $hours = fn () => $method->invoke($service, $employee, collect([$leave, $leave]), '2026-11-18', '2026-11-20');
        Holiday::create(['company_id' => $this->foreign->id, 'name' => 'Foreign', 'date' => '2026-11-19', 'is_day_off' => true]);
        $this->assertEquals(8, $hours());
        $holiday = Holiday::create(['company_id' => $this->company->id, 'name' => 'Short day', 'date' => '2026-11-19', 'is_day_off' => false]);
        $this->assertEquals(8, $hours());
        $holiday->update(['is_day_off' => true]);
        $this->assertEquals(0, $hours());
        $holiday->delete();
        Holiday::create(['company_id' => null, 'name' => 'Global', 'date' => '2026-11-19', 'is_day_off' => true]);
        $this->assertEquals(0, $hours());
    }

    public function test_company_deletion_does_not_promote_settings_to_global(): void
    {
        Setting::create(['group' => 'corpms', 'key' => 'ai.model', 'value' => 'private-model', 'company_id' => $this->foreign->id]);
        $this->foreign->delete();
        $this->assertDatabaseMissing('settings', ['value' => 'private-model']);
    }

    public function test_global_settings_are_unique_at_database_level(): void
    {
        Setting::create(['group' => 'corpms', 'key' => 'ai.model', 'value' => 'first', 'company_id' => null]);
        $this->expectException(QueryException::class);
        DB::table('settings')->insert(['group' => 'corpms', 'key' => 'ai.model', 'value' => 'second', 'company_id' => null]);
    }

    public function test_secret_settings_are_encrypted_at_rest_and_not_shown_in_html(): void
    {
        $admin = $this->employee('super_admin');
        $this->actingAs($admin->user)->post('/settings', ['settings' => ['corpms.ai.api_key' => 'audit-secret-value']])
            ->assertSessionHasNoErrors();
        $stored = DB::table('settings')->where('key', 'ai.api_key')->value('value');
        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertStringNotContainsString('audit-secret-value', $stored);
        $this->assertSame('audit-secret-value', app(SettingsService::class)->get('corpms.ai.api_key'));
        $this->get('/settings')->assertOk()->assertDontSee('audit-secret-value');
    }

    public function test_company_endpoint_cannot_inherit_installation_api_key(): void
    {
        $service = new SettingsService;
        $service->useCompany(null);
        $service->set('corpms.ai.base_url', 'https://installation.example/v1');
        $service->set('corpms.ai.api_key', 'installation-secret');
        $service->useCompany($this->company->id);
        $this->assertSame('installation-secret', $service->get('corpms.ai.api_key'));
        $service->set('corpms.ai.base_url', 'https://company-provider.example/v1');
        $this->assertSame('', $service->get('corpms.ai.api_key'));
        $service->set('corpms.ai.api_key', 'company-secret');
        $this->assertSame('company-secret', $service->get('corpms.ai.api_key'));
    }

    public function test_invalid_setting_does_not_partially_update_valid_setting(): void
    {
        $admin = $this->employee('super_admin');
        $this->actingAs($admin->user)->post('/settings', ['settings' => [
            'corpms.tasks.max_subtask_depth' => 5, 'corpms.signature.otp_ttl' => -1,
        ]])->assertSessionHasErrors();
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_resetting_setting_restores_fallback_in_same_service(): void
    {
        $service = new SettingsService;
        $service->useCompany(null);
        $service->set('corpms.ai.model', 'global-model');
        $service->useCompany($this->company->id);
        $service->set('corpms.ai.model', 'own-model');
        $this->assertSame('own-model', $service->get('corpms.ai.model'));
        $service->set('corpms.ai.model', null);
        $this->assertSame('global-model', $service->get('corpms.ai.model'));
    }

    public function test_disabled_device_token_does_not_reenable_device(): void
    {
        [$device, $token] = app(BiometricService::class)->registerDevice([
            'company_id' => $this->company->id, 'name' => 'Disabled', 'serial_number' => 'DISABLED-TEST', 'direction' => 'both',
        ]);
        $device->update(['status' => 'disabled']);
        $this->withToken($token)->getJson('/api/v1/biometric/device/status')->assertUnauthorized();
        $this->assertSame('disabled', $device->fresh()->status->value);
    }

    public function test_attendance_calendar_ignores_foreign_and_working_holidays(): void
    {
        $day = Carbon::parse('2026-11-19');
        $service = app(AttendanceService::class);
        Holiday::create(['company_id' => $this->foreign->id, 'name' => 'Foreign', 'date' => $day, 'is_day_off' => true]);
        Holiday::create(['company_id' => $this->company->id, 'name' => 'Working', 'date' => $day, 'is_day_off' => false]);
        $this->assertFalse($service->isDayOff($day, $this->company->id));
        $this->assertTrue($service->isDayOff($day, $this->foreign->id));
    }

    public function test_revoked_role_is_not_cached_across_requests(): void
    {
        $employee = $this->employee('super_admin');
        $this->actingAs($employee->user)->get('/settings')->assertOk();
        $employee->roles()->detach();
        $this->get('/settings')->assertForbidden();
    }

    public function test_permission_matrix_is_loaded_with_bounded_query_count(): void
    {
        $employee = $this->employee('company_director');
        $service = new PermissionService;
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (array_keys(PermissionMatrix::all()) as $permission) {
            $service->can($employee->user, $permission);
        }
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(5, $count, 'Permission checks must not query once per sidebar item');
        $this->assertSame(PermissionScope::Company, $service->can($employee->user, 'tasks.view'));
    }

    public function test_company_only_module_rejects_narrow_grant(): void
    {
        $employee = $this->employee();
        EmployeePermissionOverride::create(['employee_id' => $employee->id,
            'permission_id' => DB::table('permissions')->where('slug', 'finance.view')->value('id'), 'scope' => 'own']);
        $this->actingAs($employee->user)->get('/finance/accounts')->assertForbidden();
    }

    public function test_background_ai_retry_does_not_send_data_after_dismissal(): void
    {
        $employee = $this->employee('super_admin');
        $conversation = AiConversation::create(['employee_id' => $employee->id, 'question' => 'Test question',
            'answer' => 'Local answer', 'grounded' => false]);
        $employee->update(['status' => 'dismissed']);
        Http::fake();
        (new RetryAiAnswer($conversation->id))->handle();
        Http::assertNothingSent();
        $this->assertSame('Local answer', $conversation->fresh()->answer);
    }

    public function test_profile_fill_does_not_invent_or_persist_personal_data(): void
    {
        $employee = $this->employee();
        $employee->update(['email' => null, 'address' => null, 'birth_date' => null, 'telegram_chat_id' => null]);
        Http::fake();
        $this->actingAs($employee->user)->post('/profile/ai-fill')->assertSessionHas('profile_suggestions.email', $employee->user->email);
        Http::assertNothingSent();
        foreach (['email', 'address', 'birth_date', 'telegram_chat_id'] as $field) {
            $this->assertNull($employee->fresh()->getAttribute($field));
        }
    }

    public function test_report_scope_cannot_be_widened_by_analytics_permission(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        foreach (['reports.view' => 'own', 'analytics.view' => 'company'] as $permission => $scope) {
            EmployeePermissionOverride::create(['employee_id' => $employee->id,
                'permission_id' => DB::table('permissions')->where('slug', $permission)->value('id'), 'scope' => $scope]);
        }
        $this->actingAs($employee->user)->get('/reports/attendance')->assertOk()->assertSee($employee->last_name)->assertDontSee($other->last_name);
    }

    public function test_own_payroll_permission_does_not_reveal_other_salaries_or_export_totals(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        EmployeePermissionOverride::create(['employee_id' => $employee->id,
            'permission_id' => DB::table('permissions')->where('slug', 'payroll.view')->value('id'), 'scope' => 'own']);
        $component = SalaryComponent::create(['company_id' => $this->company->id, 'code' => 'SAL',
            'name' => 'Salary', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 0]);
        $period = PayrollPeriod::create(['company_id' => $this->company->id, 'period_start' => '2026-11-01',
            'period_end' => '2026-11-30', 'status' => 'calculated', 'gross_amount' => 987654, 'net_amount' => 987654]);
        foreach ([$employee, $other] as $person) {
            $period->lines()->create(['employee_id' => $person->id, 'component_id' => $component->id,
                'quantity' => 1, 'rate' => 100, 'amount' => 100, 'source' => 'auto']);
            EmployeeSalary::create(['employee_id' => $person->id,
                'base_amount' => 100, 'currency' => 'RUB', 'effective_from' => '2026-01-01']);
        }
        $this->actingAs($employee->user)->get('/payroll/periods/'.$period->id)->assertOk()->assertDontSee($other->last_name);
        $this->get('/payroll/salaries')->assertOk()->assertDontSee($other->last_name);
        $response = $this->get('/payroll/periods/'.$period->id.'/export')->assertOk();
        $this->assertStringNotContainsString($other->last_name, $response->streamedContent());
        $this->assertStringNotContainsString('987654', $response->streamedContent());
        $this->assertStringContainsString('100.00', $response->streamedContent());
    }
}
