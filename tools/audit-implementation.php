<?php

use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Payroll\Services\PayrollCalculationService;
use App\Http\Controllers\Web\UserController;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\PermissionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Read-only product audit: all database writes are restricted to SQLite :memory:.
require __DIR__.'/../vendor/autoload.php';
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_URL=');
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
    'database.connections.sqlite.url' => null, 'cache.default' => 'array',
    'session.driver' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array',
    'corpms.ai.base_url' => '', 'corpms.ai.api_key' => '']);
DB::purge();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    throw new RuntimeException('Audit requires an in-memory database');
}
Http::preventStrayRequests();
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
$company = Company::create(['name' => 'Audit A', 'code' => 'audit-a']);
$otherCompany = Company::create(['name' => 'Audit B', 'code' => 'audit-b']);
$user = User::factory()->create();
$employee = Employee::create(['company_id' => $company->id, 'user_id' => $user->id,
    'employee_no' => 'AUD-1', 'first_name' => 'Audit', 'last_name' => 'Director', 'status' => 'active']);
$employee->roles()->attach(Role::whereIn('slug', ['employee', 'company_director'])->pluck('id'));
$permissions = new PermissionService;
echo 'multi_role_tasks_view='.$permissions->can($user, 'tasks.view')->value.' expected=company'.PHP_EOL;
$foreign = Employee::create(['company_id' => $otherCompany->id, 'employee_no' => 'AUD-2',
    'first_name' => 'Foreign', 'last_name' => 'Candidate', 'status' => 'active']);
Auth::login($user);
$request = Request::create('/users');
$request->setUserResolver(fn () => $user);
$view = app(UserController::class)->index($request);
echo 'foreign_candidate_exposed='.(int) $view->getData()['candidates']->contains('id', $foreign->id).' expected=0'.PHP_EOL;
$employee->update(['status' => 'dismissed']);
echo 'dismissed_employee_permission='.(new PermissionService)->can($user, 'tasks.view')->value.' expected=denied'.PHP_EOL;
echo 'dismissed_employee_password_login='.(int) Auth::attempt(['email' => $user->email, 'password' => 'password']).' expected=0'.PHP_EOL;
// Two independently loaded requests; the first receives the purchase, then the stale second request runs.
$warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Audit warehouse', 'code' => 'AUD']);
$product = Product::create(['company_id' => $company->id, 'name' => 'Audit product', 'sku' => 'AUD']);
$supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Audit supplier']);
$order = PurchaseOrder::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id,
    'supplier_id' => $supplier->id, 'number' => 'AUD-1', 'status' => 'ordered', 'order_date' => '2026-11-19']);
$order->items()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 1]);
$staleOrder = $order->fresh();
$inventory = app(InventoryService::class);
$inventory->receive($order);
$inventory->receive($staleOrder);
echo 'double_receive_stock='.$inventory->stock($company->id, $warehouse->id, $product->id).' expected=10'.PHP_EOL;
$payroll = app(PayrollCalculationService::class);
$leave = new LeaveRequest(['from_date' => '2026-11-19', 'to_date' => '2026-11-19']);
$method = new ReflectionMethod($payroll, 'paidLeaveHours');
$before = $method->invoke($payroll, $employee, collect([$leave]), '2026-11-18', '2026-11-20');
Holiday::create(['company_id' => $otherCompany->id, 'name' => 'Foreign holiday', 'date' => '2026-11-19']);
$after = $method->invoke($payroll, $employee, collect([$leave]), '2026-11-18', '2026-11-20');
echo 'paid_leave_hours_before_foreign_holiday='.$before.' after='.$after.' expected=unchanged'.PHP_EOL;
Holiday::create(['company_id' => $company->id, 'name' => 'Own holiday', 'date' => '2026-11-19', 'is_day_off' => true]);
echo 'paid_leave_hours_on_own_holiday='.$method->invoke($payroll, $employee, collect([$leave]), '2026-11-18', '2026-11-20').' expected=0'.PHP_EOL;
$deptA = Department::create(['company_id' => $company->id, 'name' => 'Audit department A', 'code' => 'AUD-A']);
$deptB = Department::create(['company_id' => $company->id, 'name' => 'Audit department B', 'code' => 'AUD-B']);
$director = User::factory()->create();
$directorEmployee = Employee::create(['company_id' => $company->id, 'user_id' => $director->id,
    'department_id' => $deptA->id, 'employee_no' => 'AUD-3', 'first_name' => 'Audit', 'last_name' => 'Department director', 'status' => 'active']);
$directorEmployee->roles()->attach(Role::where('slug', 'department_director')->value('id'));
$employee->update(['status' => 'active', 'department_id' => $deptB->id]);
Auth::login($director);
$request = Request::create('/employees/'.$employee->id);
$request->setUserResolver(fn () => $director);
$response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo 'other_department_employee_http_status='.$response->getStatusCode().' expected=403'.PHP_EOL;
