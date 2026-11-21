<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Web\AccountingChartController;
use App\Http\Controllers\Web\AccountingDocumentController;
use App\Http\Controllers\Web\AccountingEntryController;
use App\Http\Controllers\Web\AccountingReportController;
use App\Http\Controllers\Web\AiController;
use App\Http\Controllers\Web\AnalyticsController;
use App\Http\Controllers\Web\BackupController;
use App\Http\Controllers\Web\CertificateController;
use App\Http\Controllers\Web\AttendanceController;
use App\Http\Controllers\Web\AttendanceCorrectionController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\BiDashboardController;
use App\Http\Controllers\Web\BiMetricsController;
use App\Http\Controllers\Web\BiometricController;
use App\Http\Controllers\Web\ChatController;
use App\Http\Controllers\Web\CompanyController;
use App\Http\Controllers\Web\CrmActivityController;
use App\Http\Controllers\Web\CrmClientController;
use App\Http\Controllers\Web\CrmDealController;
use App\Http\Controllers\Web\CrmLeadController;
use App\Http\Controllers\Web\DepartmentController;
use App\Http\Controllers\Web\DocumentController;
use App\Http\Controllers\Web\DocumentTemplateController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\FinAccountController;
use App\Http\Controllers\Web\FinBudgetController;
use App\Http\Controllers\Web\FinCategoryController;
use App\Http\Controllers\Web\FinInvoiceController;
use App\Http\Controllers\Web\FinTransactionController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\IntegrationController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\LmsController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\InventoryController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\PayrollController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\ProjectMemberController;
use App\Http\Controllers\Web\ProjectMilestoneController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\RfidController;
use App\Http\Controllers\Web\ScheduleController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\TaskController;
use App\Http\Controllers\Web\TwoFactorController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // IP-throttle 30/мин — защита от флуда; основной антибрутфорс — лок по
    // аккаунту 5 неудач/15 мин внутри AuthController (терпимо к офису за NAT)
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');

    // Восстановление пароля (P1-2 аудита): ссылка на email + сброс по токену
    Route::get('/forgot-password', [PasswordResetController::class, 'forgotForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:10,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');

    Route::get('/login/2fa', [TwoFactorController::class, 'showChallenge'])->name('login.2fa');
    Route::post('/login/2fa', [TwoFactorController::class, 'verifyChallenge'])->middleware('throttle:10,1')->name('login.2fa.verify');

    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::view('/', 'welcome')->name('welcome');
Route::redirect('/welcome', '/');
Route::redirect('/landing', '/')->name('landing');

Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/cookies', 'legal.cookies')->name('cookies');
Route::get('/sitemap.xml', function () {
    $urls = ['/', '/privacy', '/terms', '/cookies'];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $u) {
        $xml .= '<url><loc>'.e(url($u)).'</loc></url>';
    }
    $xml .= '</urlset>';
    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::get('/profile', [UserController::class, 'profile'])->name('profile.show');
    Route::put('/profile/password', [UserController::class, 'changePassword'])->name('profile.password');
    Route::get('/profile/2fa', [TwoFactorController::class, 'index'])->name('profile.2fa');
    Route::post('/profile/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('profile.2fa.confirm');
    Route::post('/profile/2fa/disable', [TwoFactorController::class, 'disable'])->name('profile.2fa.disable');
    Route::put('/profile', [UserController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/photo', [UserController::class, 'uploadPhoto'])->name('profile.photo');
    Route::post('/profile/ai-fill', [UserController::class, 'aiFillProfile'])->middleware('throttle:10,1')->name('profile.ai-fill');

    /* ---------- организация ---------- */
    Route::get('/companies', [CompanyController::class, 'index'])->middleware('permission:companies.view')->name('companies.index');
    Route::post('/companies', [CompanyController::class, 'store'])->middleware('permission:companies.manage')->name('companies.store');
    Route::patch('/companies/{company}', [CompanyController::class, 'update'])->middleware('permission:companies.manage')->name('companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->middleware('permission:companies.manage')->name('companies.destroy');

    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:departments.view')->name('departments.index');
    Route::get('/orgchart', [DepartmentController::class, 'orgChart'])->middleware('permission:org_chart.view')->name('orgchart.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->middleware('permission:departments.manage')->name('departments.store');
    Route::patch('/departments/{department}', [DepartmentController::class, 'update'])->middleware('permission:departments.manage')->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->middleware('permission:departments.manage')->name('departments.destroy');

    Route::get('/positions', [CompanyController::class, 'positionsIndex'])->middleware('permission:positions.view')->name('positions.index');
    Route::post('/positions', [CompanyController::class, 'positionsStore'])->middleware('permission:positions.manage')->name('positions.store');
    Route::patch('/positions/{position}', [CompanyController::class, 'positionsUpdate'])->middleware('permission:positions.manage')->name('positions.update');
    Route::delete('/positions/{position}', [CompanyController::class, 'positionsDestroy'])->middleware('permission:positions.manage')->name('positions.destroy');

    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view')->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:employees.manage')->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view')->name('employees.show');
    Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.manage')->name('employees.update');
    Route::put('/employees/{employee}/roles', [EmployeeController::class, 'updateRoles'])->middleware('permission:employees.roles')->name('employees.roles');
    Route::get('/employees/{employee}/subordinates', [EmployeeController::class, 'subordinates'])->middleware('permission:employees.view')->name('employees.subordinates');

    /* ---------- графики и отпуска ---------- */
    Route::get('/schedules', [ScheduleController::class, 'schedules'])->middleware('permission:schedules.view')->name('schedules.index');
    Route::post('/schedules', [ScheduleController::class, 'storeSchedule'])->middleware('permission:schedules.manage')->name('schedules.store');
    Route::patch('/schedules/{schedule}', [ScheduleController::class, 'updateSchedule'])->middleware('permission:schedules.manage')->name('schedules.update');
    Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroySchedule'])->middleware('permission:schedules.manage')->name('schedules.destroy');
    Route::post('/schedules/assignments', [ScheduleController::class, 'assignSchedule'])->middleware('permission:schedules.manage')->name('schedules.assign');
    Route::get('/holidays', [ScheduleController::class, 'holidays'])->middleware('permission:schedules.view')->name('schedules.holidays');
    Route::post('/holidays', [ScheduleController::class, 'storeHoliday'])->middleware('permission:holidays.manage')->name('holidays.store');
    Route::delete('/holidays/{holiday}', [ScheduleController::class, 'destroyHoliday'])->middleware('permission:holidays.manage')->name('holidays.destroy');
    Route::get('/my-schedule', [ScheduleController::class, 'mySchedule'])->middleware('permission:attendance.view')->name('schedules.mine');
    Route::get('/leaves', [ScheduleController::class, 'leaves'])->middleware('permission:leaves.view')->name('schedules.leaves');
    Route::post('/leaves', [ScheduleController::class, 'requestLeave'])->middleware('permission:leaves.request')->name('leaves.request');
    Route::put('/leaves/{leave}/decision', [ScheduleController::class, 'decideLeave'])->middleware('permission:leaves.approve')->name('leaves.decide');

    /* ---------- посещаемость ---------- */
    Route::get('/attendance', [AttendanceController::class, 'index'])->middleware('permission:attendance.view')->name('attendance.index');
    Route::get('/attendance/live', [AttendanceController::class, 'index'])->middleware('permission:attendance.live.view')->name('attendance.live');
    Route::get('/attendance/live/feed', [AttendanceController::class, 'liveFeed'])->middleware('permission:attendance.live.view')->name('attendance.liveFeed');
    Route::get('/attendance/timesheet', [AttendanceController::class, 'timesheet'])->middleware('permission:attendance.timesheet.view')->name('attendance.timesheet');
    Route::get('/attendance/timesheet/export', [AttendanceController::class, 'timesheetExport'])->middleware('permission:attendance.timesheet.export')->name('attendance.timesheet.export');
    Route::get('/attendance/anomalies', [AttendanceController::class, 'anomalies'])->middleware('permission:attendance.anomalies.view')->name('attendance.anomalies');
    Route::put('/attendance/anomalies/{anomaly}', [AttendanceController::class, 'resolveAnomaly'])->middleware('permission:attendance.anomalies.manage')->name('attendance.anomalies.resolve');
    Route::get('/attendance/corrections', [AttendanceCorrectionController::class, 'index'])->middleware('permission:attendance.correct')->name('attendance.corrections');
    Route::post('/attendance/corrections', [AttendanceCorrectionController::class, 'store'])->middleware('permission:attendance.correct')->name('attendance.corrections.store');
    Route::post('/attendance/corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->middleware('permission:attendance.corrections.approve')->name('attendance.corrections.approve');
    Route::post('/attendance/corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->middleware('permission:attendance.corrections.approve')->name('attendance.corrections.reject');

    /* ---------- зарплата ---------- */
    Route::get('/payroll', [PayrollController::class, 'index'])->middleware('permission:payroll.view')->name('payroll.index');
    Route::post('/payroll', [PayrollController::class, 'store'])->middleware('permission:payroll.manage@company')->name('payroll.store');
    Route::get('/payroll/salaries', [PayrollController::class, 'salaries'])->middleware('permission:payroll.view')->name('payroll.salaries');
    Route::post('/payroll/salaries', [PayrollController::class, 'storeSalary'])->middleware('permission:payroll.manage@company')->name('payroll.salaries.store');
    Route::post('/payroll/components', [PayrollController::class, 'storeComponent'])->middleware('permission:payroll.manage@company')->name('payroll.components.store');
    Route::post('/payroll/components/{component}/toggle', [PayrollController::class, 'toggleComponent'])->middleware('permission:payroll.manage@company')->name('payroll.components.toggle');
    Route::get('/payroll/periods/{period}', [PayrollController::class, 'show'])->middleware('permission:payroll.view')->name('payroll.show');
    Route::post('/payroll/periods/{period}/calculate', [PayrollController::class, 'calculate'])->middleware('permission:payroll.manage@company')->name('payroll.calculate');
    Route::post('/payroll/periods/{period}/approve', [PayrollController::class, 'approve'])->middleware('permission:payroll.approve@company')->name('payroll.approve');
    Route::post('/payroll/periods/{period}/pay', [PayrollController::class, 'pay'])->middleware('permission:payroll.approve@company')->name('payroll.pay');
    Route::get('/payroll/periods/{period}/export', [PayrollController::class, 'export'])->middleware('permission:payroll.view')->name('payroll.export');

    /* ---------- склад и закупки (E1: ERP) ---------- */
    Route::get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view')->name('inventory.index');
    Route::get('/inventory/products', [InventoryController::class, 'products'])->middleware('permission:inventory.view')->name('inventory.products');
    Route::post('/inventory/products', [InventoryController::class, 'storeProduct'])->middleware('permission:inventory.manage@company')->name('inventory.products.store');
    Route::patch('/inventory/products/{product}', [InventoryController::class, 'updateProduct'])->middleware('permission:inventory.manage@company')->name('inventory.products.update');
    Route::post('/inventory/products/{product}/toggle', [InventoryController::class, 'toggleProduct'])->middleware('permission:inventory.manage@company')->name('inventory.products.toggle');
    Route::get('/inventory/warehouses', [InventoryController::class, 'warehouses'])->middleware('permission:inventory.view')->name('inventory.warehouses');
    Route::post('/inventory/warehouses', [InventoryController::class, 'storeWarehouse'])->middleware('permission:inventory.manage@company')->name('inventory.warehouses.store');
    Route::post('/inventory/warehouses/{warehouse}/toggle', [InventoryController::class, 'toggleWarehouse'])->middleware('permission:inventory.manage@company')->name('inventory.warehouses.toggle');
    Route::get('/inventory/suppliers', [InventoryController::class, 'suppliers'])->middleware('permission:inventory.view')->name('inventory.suppliers');
    Route::post('/inventory/suppliers', [InventoryController::class, 'storeSupplier'])->middleware('permission:inventory.manage@company')->name('inventory.suppliers.store');
    Route::post('/inventory/suppliers/{supplier}/toggle', [InventoryController::class, 'toggleSupplier'])->middleware('permission:inventory.manage@company')->name('inventory.suppliers.toggle');
    Route::get('/inventory/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view')->name('inventory.movements');
    Route::post('/inventory/movements', [InventoryController::class, 'storeMovement'])->middleware('permission:inventory.move@company')->name('inventory.movements.store');

    Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('permission:purchases.view')->name('purchases.index');
    Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.manage@company')->name('purchases.store');
    Route::get('/purchases/{order}', [PurchaseController::class, 'show'])->middleware('permission:purchases.view')->name('purchases.show');
    Route::post('/purchases/{order}/order', [PurchaseController::class, 'order'])->middleware('permission:purchases.manage@company')->name('purchases.order');
    Route::post('/purchases/{order}/receive', [PurchaseController::class, 'receive'])->middleware('permission:purchases.manage@company')->name('purchases.receive');
    Route::post('/purchases/{order}/cancel', [PurchaseController::class, 'cancel'])->middleware('permission:purchases.manage@company')->name('purchases.cancel');
    Route::post('/purchases/{order}/items', [PurchaseController::class, 'addItem'])->middleware('permission:purchases.manage@company')->name('purchases.items.store');
    Route::post('/purchases/{order}/items/{item}/remove', [PurchaseController::class, 'removeItem'])->middleware('permission:purchases.manage@company')->name('purchases.items.remove');

    /* ---------- CRM (E2) ---------- */
    Route::get('/crm/clients', [CrmClientController::class, 'index'])->middleware('permission:crm.view')->name('crm.clients.index');
    Route::post('/crm/clients', [CrmClientController::class, 'store'])->middleware('permission:crm.manage@company')->name('crm.clients.store');
    Route::get('/crm/clients/{client}', [CrmClientController::class, 'show'])->middleware('permission:crm.view')->name('crm.clients.show');
    Route::patch('/crm/clients/{client}', [CrmClientController::class, 'update'])->middleware('permission:crm.manage@company')->name('crm.clients.update');
    Route::post('/crm/clients/{client}/toggle', [CrmClientController::class, 'toggle'])->middleware('permission:crm.manage@company')->name('crm.clients.toggle');
    Route::post('/crm/clients/{client}/contacts', [CrmClientController::class, 'storeContact'])->middleware('permission:crm.manage@company')->name('crm.clients.contacts.store');
    Route::delete('/crm/clients/{client}/contacts/{contact}', [CrmClientController::class, 'destroyContact'])->middleware('permission:crm.manage@company')->name('crm.clients.contacts.destroy');

    Route::get('/crm/leads', [CrmLeadController::class, 'index'])->middleware('permission:crm.view')->name('crm.leads.index');
    Route::post('/crm/leads', [CrmLeadController::class, 'store'])->middleware('permission:crm.manage@company')->name('crm.leads.store');
    Route::post('/crm/leads/{lead}/qualify', [CrmLeadController::class, 'qualify'])->middleware('permission:crm.manage@company')->name('crm.leads.qualify');
    Route::post('/crm/leads/{lead}/lose', [CrmLeadController::class, 'lose'])->middleware('permission:crm.manage@company')->name('crm.leads.lose');
    Route::post('/crm/leads/{lead}/convert', [CrmLeadController::class, 'convert'])->middleware('permission:crm.deals.manage@company')->name('crm.leads.convert');

    Route::get('/crm/deals', [CrmDealController::class, 'index'])->middleware('permission:crm.view')->name('crm.deals.index');
    Route::post('/crm/deals', [CrmDealController::class, 'store'])->middleware('permission:crm.deals.manage@company')->name('crm.deals.store');
    Route::patch('/crm/deals/{deal}', [CrmDealController::class, 'update'])->middleware('permission:crm.deals.manage@company')->name('crm.deals.update');
    Route::post('/crm/deals/{deal}/move', [CrmDealController::class, 'move'])->middleware('permission:crm.deals.manage@company')->name('crm.deals.move');

    Route::get('/crm/activities', [CrmActivityController::class, 'index'])->middleware('permission:crm.view')->name('crm.activities.index');
    Route::post('/crm/activities', [CrmActivityController::class, 'store'])->middleware('permission:crm.manage@company')->name('crm.activities.store');
    Route::post('/crm/activities/{activity}/complete', [CrmActivityController::class, 'complete'])->middleware('permission:crm.manage@company')->name('crm.activities.complete');
    Route::post('/crm/activities/{activity}/reopen', [CrmActivityController::class, 'reopen'])->middleware('permission:crm.manage@company')->name('crm.activities.reopen');

    /* ---------- финансы (E3) ---------- */
    Route::get('/finance/accounts', [FinAccountController::class, 'index'])->middleware('permission:finance.view')->name('finance.accounts.index');
    Route::post('/finance/accounts', [FinAccountController::class, 'store'])->middleware('permission:finance.manage@company')->name('finance.accounts.store');
    Route::post('/finance/accounts/{account}/toggle', [FinAccountController::class, 'toggle'])->middleware('permission:finance.manage@company')->name('finance.accounts.toggle');

    Route::get('/finance/categories', [FinCategoryController::class, 'index'])->middleware('permission:finance.view')->name('finance.categories.index');
    Route::post('/finance/categories', [FinCategoryController::class, 'store'])->middleware('permission:finance.manage@company')->name('finance.categories.store');
    Route::post('/finance/categories/{category}/toggle', [FinCategoryController::class, 'toggle'])->middleware('permission:finance.manage@company')->name('finance.categories.toggle');

    Route::get('/finance/transactions', [FinTransactionController::class, 'index'])->middleware('permission:finance.view')->name('finance.transactions.index');
    Route::post('/finance/transactions', [FinTransactionController::class, 'store'])->middleware('permission:finance.manage@company')->name('finance.transactions.store');

    Route::get('/finance/invoices', [FinInvoiceController::class, 'index'])->middleware('permission:finance.view')->name('finance.invoices.index');
    Route::post('/finance/invoices', [FinInvoiceController::class, 'store'])->middleware('permission:finance.invoices.manage@company')->name('finance.invoices.store');
    Route::get('/finance/invoices/{invoice}', [FinInvoiceController::class, 'show'])->middleware('permission:finance.view')->name('finance.invoices.show');
    Route::post('/finance/invoices/{invoice}/issue', [FinInvoiceController::class, 'issue'])->middleware('permission:finance.invoices.manage@company')->name('finance.invoices.issue');
    Route::post('/finance/invoices/{invoice}/cancel', [FinInvoiceController::class, 'cancel'])->middleware('permission:finance.invoices.manage@company')->name('finance.invoices.cancel');
    Route::post('/finance/invoices/{invoice}/payments', [FinInvoiceController::class, 'storePayment'])->middleware('permission:finance.invoices.manage@company')->name('finance.invoices.payments.store');

    Route::get('/finance/budgets', [FinBudgetController::class, 'index'])->middleware('permission:finance.view')->name('finance.budgets.index');
    Route::post('/finance/budgets', [FinBudgetController::class, 'store'])->middleware('permission:finance.manage@company')->name('finance.budgets.store');

    /* ---------- бухгалтерия (E4) ---------- */
    Route::get('/accounting/chart', [AccountingChartController::class, 'index'])->middleware('permission:accounting.view')->name('accounting.chart.index');
    Route::post('/accounting/chart', [AccountingChartController::class, 'store'])->middleware('permission:accounting.manage@company')->name('accounting.chart.store');
    Route::post('/accounting/chart/default', [AccountingChartController::class, 'seedDefault'])->middleware('permission:accounting.manage@company')->name('accounting.chart.default');
    Route::post('/accounting/chart/{account}/toggle', [AccountingChartController::class, 'toggle'])->middleware('permission:accounting.manage@company')->name('accounting.chart.toggle');

    Route::get('/accounting/entries', [AccountingEntryController::class, 'index'])->middleware('permission:accounting.view')->name('accounting.entries.index');
    Route::post('/accounting/entries', [AccountingEntryController::class, 'store'])->middleware('permission:accounting.manage@company')->name('accounting.entries.store');
    Route::post('/accounting/sync', [AccountingEntryController::class, 'sync'])->middleware('permission:accounting.manage@company')->name('accounting.sync');
    Route::get('/accounting/entries/{entry}', [AccountingEntryController::class, 'show'])->middleware('permission:accounting.view')->name('accounting.entries.show');

    Route::get('/accounting/documents', [AccountingDocumentController::class, 'index'])->middleware('permission:accounting.view')->name('accounting.documents.index');
    Route::post('/accounting/documents', [AccountingDocumentController::class, 'store'])->middleware('permission:accounting.manage@company')->name('accounting.documents.store');
    Route::delete('/accounting/documents/{document}', [AccountingDocumentController::class, 'destroy'])->middleware('permission:accounting.manage@company')->name('accounting.documents.destroy');

    Route::get('/accounting/reports', [AccountingReportController::class, 'index'])->middleware('permission:accounting.view')->name('accounting.reports.index');

    /* ---------- проекты (E5) ---------- */
    Route::get('/projects', [ProjectController::class, 'index'])->middleware('permission:projects.view')->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('permission:projects.manage@company')->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->middleware('permission:projects.view')->name('projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->middleware('permission:projects.manage@company')->name('projects.update');
    Route::post('/projects/{project}/status', [ProjectController::class, 'status'])->middleware('permission:projects.manage@company')->name('projects.status');
    Route::post('/projects/{project}/tasks', [ProjectController::class, 'attachTask'])->middleware('permission:projects.manage@company')->name('projects.tasks.attach');
    Route::delete('/projects/{project}/tasks/{task}', [ProjectController::class, 'detachTask'])->middleware('permission:projects.manage@company')->name('projects.tasks.detach');

    Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->middleware('permission:projects.manage@company')->name('projects.members.store');
    Route::delete('/projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy'])->middleware('permission:projects.manage@company')->name('projects.members.destroy');

    Route::post('/projects/{project}/milestones', [ProjectMilestoneController::class, 'store'])->middleware('permission:projects.manage@company')->name('projects.milestones.store');
    Route::post('/projects/{project}/milestones/{milestone}/toggle', [ProjectMilestoneController::class, 'toggle'])->middleware('permission:projects.manage@company')->name('projects.milestones.toggle');
    Route::delete('/projects/{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'destroy'])->middleware('permission:projects.manage@company')->name('projects.milestones.destroy');

    /* ---------- BI (E6) ---------- */
    Route::get('/bi', [BiDashboardController::class, 'index'])->middleware('permission:bi.view')->name('bi.index');
    Route::get('/bi/metrics', [BiMetricsController::class, 'index'])->middleware('permission:bi.view')->name('bi.metrics.index');
    Route::post('/bi/rebuild', [BiDashboardController::class, 'rebuild'])->middleware('permission:bi.manage@company')->name('bi.rebuild');
    Route::post('/bi/dashboards', [BiDashboardController::class, 'store'])->middleware('permission:bi.manage@company')->name('bi.dashboards.store');
    Route::get('/bi/dashboards/{dashboard}', [BiDashboardController::class, 'show'])->middleware('permission:bi.view')->name('bi.dashboards.show');
    Route::post('/bi/dashboards/{dashboard}/widgets', [BiDashboardController::class, 'storeWidget'])->middleware('permission:bi.manage@company')->name('bi.widgets.store');
    Route::delete('/bi/dashboards/{dashboard}/widgets/{widget}', [BiDashboardController::class, 'destroyWidget'])->middleware('permission:bi.manage@company')->name('bi.widgets.destroy');

    /* ---------- документы и подписи ---------- */
    Route::get('/documents', [DocumentController::class, 'index'])->middleware('permission:documents.view')->name('documents.index');
    Route::get('/documents/mine', [DocumentController::class, 'mine'])->middleware('permission:documents.view')->name('documents.mine');
    Route::get('/documents/templates', [DocumentTemplateController::class, 'index'])->middleware('permission:documents.templates.view')->name('documents.templates');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('permission:documents.create')->name('documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->middleware('permission:documents.view')->name('documents.show');
    Route::post('/documents/{document}/version', [DocumentController::class, 'addVersion'])->middleware('permission:documents.view')->name('documents.version');
    Route::get('/documents/{document}/versions/{version}/file', [DocumentController::class, 'downloadVersion'])->middleware('permission:documents.view')->name('documents.version.file');
    Route::post('/documents/{document}/submit', [DocumentController::class, 'submit'])->middleware('permission:documents.submit')->name('documents.submit');
    Route::post('/documents/{document}/decide', [DocumentController::class, 'decide'])->middleware('permission:documents.approve')->name('documents.decide');
    Route::post('/documents/{document}/register', [DocumentController::class, 'register'])->middleware('permission:documents.approve')->name('documents.register');
    Route::post('/documents/{document}/archive', [DocumentController::class, 'archive'])->middleware('permission:documents.archive')->name('documents.archive');
    Route::post('/documents/{document}/signatures/request', [DocumentController::class, 'requestSignatures'])->middleware('permission:signatures.request')->name('documents.signature.request');
    Route::post('/documents/{document}/signatures/{signatureRequest}/sign', [DocumentController::class, 'sign'])->middleware('permission:signatures.sign')->name('documents.signature.sign');
    Route::post('/documents/{document}/signatures/{signatureRequest}/decline', [DocumentController::class, 'declineSignature'])->middleware('permission:signatures.sign')->name('documents.signature.decline');
    Route::post('/signatures/{signature}/verify', [DocumentController::class, 'verifySignature'])->middleware('permission:signatures.verify')->name('documents.signature.verify');
    Route::post('/signatures/{signature}/revoke', [DocumentController::class, 'revokeSignature'])->middleware('permission:signatures.revoke')->name('documents.signature.revoke');

    /* ---------- маршруты согласования, шаблоны, учётки, интеграции ---------- */
    Route::get('/workflows', [WorkflowController::class, 'index'])->middleware('permission:workflows.view')->name('workflows.index');
    Route::post('/workflows', [WorkflowController::class, 'store'])->middleware('permission:workflows.manage')->name('workflows.store');

    Route::post('/documents/templates/category', [DocumentTemplateController::class, 'storeCategory'])->middleware('permission:documents.categories.manage')->name('documents.categories.store');
    Route::post('/documents/templates', [DocumentTemplateController::class, 'storeTemplate'])->middleware('permission:documents.templates.manage')->name('documents.templates.store');
    Route::post('/documents/templates/{template}/create', [DocumentTemplateController::class, 'createFromTemplate'])->middleware('permission:documents.create')->name('documents.templates.create');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage')->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage')->name('users.store');
    Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])->middleware('permission:users.manage')->name('users.password');

    Route::get('/integrations', [IntegrationController::class, 'index'])->middleware('permission:integrations.manage')->name('integrations.index');

    /* ---------- биометрия ---------- */
    Route::get('/biometrics', [BiometricController::class, 'index'])->middleware('permission:biometry.devices.view')->name('biometrics.index');
    Route::get('/biometrics/events', [BiometricController::class, 'events'])->middleware('permission:biometry.events.view')->name('biometrics.events');
    Route::get('/biometrics/privacy', [BiometricController::class, 'privacy'])->middleware('permission:biometry.privacy.manage')->name('biometrics.privacy');
    Route::post('/biometrics/privacy', [BiometricController::class, 'privacyUpdate'])->middleware('permission:biometry.privacy.manage')->name('biometrics.privacy.save');

    /* ---------- RFID (п.72 ТЗ) ---------- */
    Route::get('/rfid', [RfidController::class, 'index'])->middleware('permission:biometry.rfid.view')->name('rfid.index');
    Route::post('/rfid', [RfidController::class, 'issue'])->middleware('permission:biometry.rfid.manage')->name('rfid.issue');
    Route::post('/rfid/{card}/revoke', [RfidController::class, 'revoke'])->middleware('permission:biometry.rfid.manage')->name('rfid.revoke');
    Route::post('/biometrics/devices', [BiometricController::class, 'registerDevice'])->middleware('permission:biometry.devices.manage')->name('biometrics.devices.store');
    Route::post('/biometrics/devices/{device}', [BiometricController::class, 'updateDevice'])->middleware('permission:biometry.devices.manage')->name('biometrics.devices.update');
    Route::post('/biometrics/devices/{device}/delete', [BiometricController::class, 'deleteDevice'])->middleware('permission:biometry.devices.manage')->name('biometrics.devices.delete');
    Route::post('/biometrics/profiles', [BiometricController::class, 'enroll'])->middleware('permission:biometry.enroll')->name('biometrics.profile.enroll');
    Route::post('/biometrics/profiles/{profile}/disable', [BiometricController::class, 'disableProfile'])->middleware('permission:biometry.profiles.manage')->name('biometrics.profile.disable');
    Route::post('/biometrics/profiles/{profile}/delete', [BiometricController::class, 'deleteProfile'])->middleware('permission:biometry.profiles.manage')->name('biometrics.profile.delete');

    /* ---------- аналитика, отчёты, аудит, настройки, поиск ---------- */
    Route::get('/analytics', [AnalyticsController::class, 'index'])->middleware('permission:analytics.view')->name('analytics.index');

    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:reports.view')->name('reports.index');
    Route::get('/reports/hr', [ReportController::class, 'hr'])->middleware('permission:reports.view')->name('reports.hr');
    Route::get('/reports/attendance', [ReportController::class, 'attendance'])->middleware('permission:reports.view')->name('reports.attendance');
    Route::get('/reports/tasks', [ReportController::class, 'tasks'])->middleware('permission:reports.view')->name('reports.tasks');
    Route::get('/reports/{type}/export', [ReportController::class, 'export'])->where('type', 'hr|attendance|tasks')->middleware('permission:reports.export')->name('reports.export');

    Route::get('/audit', [AuditController::class, 'index'])->middleware('permission:audit.view@company')->name('audit.index');

    Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.view@company')->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage@company')->name('settings.update');

    Route::get('/backups', [BackupController::class, 'index'])->middleware('permission:settings.view@company')->name('backups.index');

    Route::get('/certificates', [CertificateController::class, 'index'])->middleware('permission:settings.view@company')->name('certificates.index');
    Route::post('/certificates', [CertificateController::class, 'store'])->middleware('permission:settings.manage@company')->name('certificates.store');
    Route::delete('/certificates/{certificate}', [CertificateController::class, 'destroy'])->middleware('permission:settings.manage@company')->name('certificates.destroy');

    Route::get('/imports', [ImportController::class, 'index'])->middleware('permission:employees.manage@company')->name('imports.index');
    Route::post('/imports/upload', [ImportController::class, 'upload'])->middleware('permission:employees.manage@company')->name('imports.upload');
    Route::get('/imports/{job}', [ImportController::class, 'show'])->middleware('permission:employees.manage@company')->name('imports.show');
    Route::post('/imports/{job}/run', [ImportController::class, 'run'])->middleware('permission:employees.manage@company')->name('imports.run');
    Route::post('/backups/db', [BackupController::class, 'runDb'])->middleware('permission:settings.manage@company')->name('backups.db');
    Route::post('/backups/files', [BackupController::class, 'runFiles'])->middleware('permission:settings.manage@company')->name('backups.files');
    Route::get('/backups/{file}', [BackupController::class, 'download'])->middleware('permission:settings.view@company')->name('backups.download');

    Route::get('/search', [SearchController::class, 'index'])->middleware(['permission:search.global', 'throttle:60,1'])->name('search.index');
    Route::get('/search/hint', [SearchController::class, 'hint'])->middleware(['permission:search.global', 'throttle:120,1'])->name('search.hint');

    Route::get('/ai', [AiController::class, 'index'])->middleware(['permission:analytics.ai.use', 'throttle:30,1'])->name('ai.index');
    Route::post('/ai', [AiController::class, 'ask'])->middleware(['permission:analytics.ai.use', 'throttle:15,1'])->name('ai.ask');
    Route::post('/ai/message', [AiController::class, 'message'])->middleware(['permission:analytics.ai.use', 'throttle:15,1'])->name('ai.message');
    Route::get('/ai/files/{file}', [AiController::class, 'download'])->where('file', '[0-9A-Za-z]{26}\.(?:png|svg|csv|html|txt|pptx)')->middleware('permission:analytics.ai.use')->name('ai.files.download');

    /* ---------- BM Chat ---------- */
    Route::get('/chat', [ChatController::class, 'index'])->middleware(['permission:chat.view', 'throttle:120,1'])->name('chat.index');
    Route::get('/chat/unread', [ChatController::class, 'unread'])->middleware(['permission:chat.view', 'throttle:120,1'])->name('chat.unread');
    Route::get('/chat/employees', [ChatController::class, 'employees'])->middleware(['permission:chat.view', 'throttle:60,1'])->name('chat.employees');
    Route::post('/chat/conversations', [ChatController::class, 'store'])->middleware(['permission:chat.view', 'throttle:30,1'])->name('chat.store');
    Route::get('/chat/{conversation}', [ChatController::class, 'index'])->middleware(['permission:chat.view', 'throttle:120,1'])->name('chat.show');
    Route::middleware('permission:chat.view')->group(function (): void {
        Route::patch('/chat/{conversation}', [ChatController::class, 'updateConversation'])->name('chat.conversations.update');
        Route::delete('/chat/{conversation}', [ChatController::class, 'deleteConversation'])->name('chat.conversations.destroy');
        Route::post('/chat/{conversation}/members', [ChatController::class, 'addMembers'])->name('chat.members.store');
        Route::delete('/chat/{conversation}/members/{employee}', [ChatController::class, 'removeMember'])
            ->whereNumber('employee')->name('chat.members.destroy');
        Route::patch('/chat/{conversation}/wallpaper', [ChatController::class, 'wallpaper'])->name('chat.wallpaper.update');
        Route::get('/chat/{conversation}/image/{kind}', [ChatController::class, 'image'])
            ->whereIn('kind', ['avatar', 'wallpaper'])->name('chat.image');
    });
    Route::get('/chat/{conversation}/messages', [ChatController::class, 'messages'])->middleware(['permission:chat.view', 'throttle:120,1'])->name('chat.messages');
    Route::get('/chat/{conversation}/events', [ChatController::class, 'events'])->middleware(['permission:chat.view', 'throttle:120,1'])->name('chat.events');
    Route::post('/chat/{conversation}/messages', [ChatController::class, 'send'])->middleware(['permission:chat.send', 'throttle:60,1'])->name('chat.send');
    Route::post('/chat/{conversation}/read', [ChatController::class, 'read'])->middleware(['permission:chat.view', 'throttle:120,1'])->name('chat.read');
    Route::post('/chat/{conversation}/subscribe', [ChatController::class, 'subscribe'])->middleware(['permission:chat.view', 'throttle:60,1'])->name('chat.subscribe');
    Route::put('/chat/messages/{message}', [ChatController::class, 'update'])->middleware(['permission:chat.send', 'throttle:60,1'])->name('chat.messages.update');
    Route::delete('/chat/messages/{message}', [ChatController::class, 'destroy'])->middleware(['permission:chat.send', 'throttle:60,1'])->name('chat.messages.destroy');
    Route::post('/chat/messages/{message}/reactions', [ChatController::class, 'react'])->middleware(['permission:chat.view', 'throttle:120,1'])->name('chat.messages.react');

    /* ---------- задачи ---------- */ Route::get('/tasks', [TaskController::class, 'index'])->middleware('permission:tasks.view')->name('tasks.index');
    Route::get('/tasks/mine', [TaskController::class, 'mine'])->middleware('permission:tasks.view')->name('tasks.mine');
    Route::get('/tasks/overdue', [TaskController::class, 'overdue'])->middleware('permission:tasks.view')->name('tasks.overdue');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('permission:tasks.create')->name('tasks.store');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->middleware('permission:tasks.view')->name('tasks.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->middleware('permission:tasks.view')->name('tasks.destroy');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->middleware('permission:tasks.view')->name('tasks.show');
    Route::put('/tasks/{task}/status', [TaskController::class, 'changeStatus'])->middleware('permission:tasks.view')->name('tasks.status');
    Route::post('/tasks/{task}/subtasks', [TaskController::class, 'createSubtask'])->middleware('permission:tasks.view')->name('tasks.subtasks');
    Route::post('/tasks/{task}/bulk', [TaskController::class, 'bulkCreate'])->middleware('permission:tasks.assign')->name('tasks.bulk');
    Route::post('/tasks/{task}/delegate', [TaskController::class, 'delegate'])->middleware('permission:tasks.delegate')->name('tasks.delegate');
    Route::post('/tasks/{task}/result', [TaskController::class, 'submitResult'])->middleware('permission:tasks.view')->name('tasks.result');
    Route::post('/tasks/{task}/comment', [TaskController::class, 'comment'])->middleware('permission:tasks.comment')->name('tasks.comment');
    Route::post('/tasks/{task}/time', [TaskController::class, 'toggleTimeTracking'])->middleware('permission:tasks.view')->name('tasks.time');
    Route::post('/tasks/{task}/attachments', [TaskController::class, 'storeAttachment'])->middleware('permission:tasks.view')->name('tasks.attachments.store');
    Route::get('/tasks/{task}/attachments/{attachment}', [TaskController::class, 'downloadAttachment'])->middleware('permission:tasks.view')->name('tasks.attachments.download');
    Route::delete('/tasks/{task}/attachments/{attachment}', [TaskController::class, 'destroyAttachment'])->middleware('permission:tasks.view')->name('tasks.attachments.destroy');

    /* ---------- LMS: обучение, доска, задания, WebRTC и Пульс Бumi ---------- */
    Route::prefix('lms')->group(function (): void {
        Route::middleware(['permission:lms.view', 'throttle:180,1'])->group(function (): void {
            Route::get('/', [LmsController::class, 'index'])->name('lms.index');
            Route::get('/courses/{course}', [LmsController::class, 'course'])->name('lms.courses.show');
            Route::get('/lessons/{lesson}', [LmsController::class, 'lesson'])->name('lms.lessons.show');
            Route::get('/whiteboards/{whiteboard}', [LmsController::class, 'whiteboard'])->name('lms.whiteboards.show');
            Route::get('/meetings/{meeting}', [LmsController::class, 'meeting'])->name('lms.meetings.show');
            Route::get('/assignments', [LmsController::class, 'assignments'])->name('lms.assignments.index');
            Route::get('/pulse', [LmsController::class, 'pulse'])->name('lms.pulse');
        });

        Route::middleware(['permission:lms.manage', 'throttle:60,1'])->group(function (): void {
            Route::post('/courses', [LmsController::class, 'storeCourse'])->name('lms.courses.store');
            Route::post('/courses/{course}/lessons', [LmsController::class, 'storeLesson'])->name('lms.lessons.store');
            Route::post('/assignments', [LmsController::class, 'storeAssignment'])->name('lms.assignments.store');
            Route::post('/assignments/{submission}/review', [LmsController::class, 'reviewAssignment'])->name('lms.assignments.review');
        });

        Route::middleware(['permission:lms.join', 'throttle:240,1'])->group(function (): void {
            Route::post('/courses/{course}/enroll', [LmsController::class, 'enroll'])->name('lms.enroll');
            Route::post('/lessons/{lesson}/progress', [LmsController::class, 'completeLesson'])->name('lms.lessons.progress');

            Route::get('/whiteboards/{whiteboard}/elements', [LmsController::class, 'whiteboardElements'])->name('lms.whiteboards.elements');
            Route::post('/whiteboards/{whiteboard}/elements', [LmsController::class, 'draw'])->name('lms.whiteboards.draw');
            Route::delete('/whiteboards/{whiteboard}/elements/{element}', [LmsController::class, 'erase'])->name('lms.whiteboards.erase');
            Route::get('/whiteboards/{whiteboard}/presence', [LmsController::class, 'presenceList'])->name('lms.whiteboards.presence');
            Route::post('/whiteboards/{whiteboard}/presence', [LmsController::class, 'presence'])->name('lms.whiteboards.presence.store');

            Route::post('/meetings', [LmsController::class, 'createMeeting'])->name('lms.meetings.store');
            Route::post('/meetings/{meeting}/join', [LmsController::class, 'joinMeeting'])->name('lms.meetings.join');
            Route::post('/meetings/{meeting}/leave', [LmsController::class, 'leaveMeeting'])->name('lms.meetings.leave');
            Route::get('/meetings/{meeting}/signals', [LmsController::class, 'signals'])->name('lms.meetings.signals');
            Route::post('/meetings/{meeting}/signals', [LmsController::class, 'signal'])->name('lms.meetings.signal');
            Route::get('/meetings/{meeting}/participants', [LmsController::class, 'participants'])->name('lms.meetings.participants');

            Route::post('/assignments/{assignment}/submit', [LmsController::class, 'submitAssignment'])->name('lms.assignments.submit');
            Route::get('/pulse/events', [LmsController::class, 'pulseEvents'])->name('lms.pulse.events');
        });
    });

    /* ---------- уведомления ---------- */
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:attendance.view')->name('notifications.index');
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('permission:attendance.view')->name('notifications.read');
});
