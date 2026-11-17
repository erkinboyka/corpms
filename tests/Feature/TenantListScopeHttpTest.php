<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Services\AuditService;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\BiometricProfile;
use App\Models\Company;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentTemplate;
use App\Models\DocumentWorkflow;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\RfidCard;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2 (спринт по аудиту): company-фильтры в списках.
 *
 * При company-scope ни один список не возвращает записей чужой компании:
 * сотрудники, аудит, табель, посещаемость, аномалии, биометрия, шаблоны,
 * workflows, RFID, дашборд.
 */
class TenantListScopeHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected Employee $adminEmployeeA;

    protected Employee $workerA;

    protected Employee $workerB;

    protected User $workerUserB;

    protected BiometricDevice $deviceA;

    protected BiometricDevice $deviceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->companyA = Company::create(['name' => 'Списки А', 'code' => 'list-a']);
        $this->companyB = Company::create(['name' => 'Списки Б', 'code' => 'list-b']);

        $superAdminRoleId = Role::query()->where('slug', 'super_admin')->value('id');

        $this->adminA = User::factory()->create(['email' => 'lists-a@tenant.test']);
        $this->adminEmployeeA = $this->employee($this->companyA, 'LA-1', 'Админ', 'А', $this->adminA->id);
        EmployeeRole::create(['employee_id' => $this->adminEmployeeA->id, 'role_id' => $superAdminRoleId]);

        $this->workerA = $this->employee($this->companyA, 'LA-2', 'Сотрудник', 'Альфа');
        $this->workerB = $this->employee($this->companyB, 'LB-1', 'Сотрудник', 'Бета');
        $this->workerUserB = User::findOrFail($this->workerB->user_id);

        $this->deviceA = $this->device($this->companyA, 'TERM-A');
        $this->deviceB = $this->device($this->companyB, 'TERM-B');

        $this->actingAs($this->adminA);
    }

    protected function employee(Company $company, string $no, string $first, string $last, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    protected function device(Company $company, string $serial): BiometricDevice
    {
        return BiometricDevice::create([
            'company_id' => $company->id,
            'name' => 'Терминал '.$serial,
            'serial_number' => $serial,
            'api_token_hash' => hash('sha256', $serial),
            'direction' => 'both',
            'status' => 'offline',
        ]);
    }

    /* ---------------- сотрудники ---------------- */

    public function test_employees_index_scoped_to_own_company(): void
    {
        $departmentB = Department::create(['company_id' => $this->companyB->id, 'name' => 'Отдел Бета-Список', 'code' => 'dep-lb']);

        $this->get('/employees')
            ->assertOk()
            ->assertSee('Альфа')
            ->assertDontSee('Бета')
            // выпадающие списки фильтров не содержат чужих справочников
            ->assertDontSee('Отдел Бета-Список');

        // чужой отдел в фильтре не даёт результатов
        $this->get('/employees?department_id='.$departmentB->id)
            ->assertOk()
            ->assertDontSee('Бета');
    }

    /* ---------------- журнал аудита ---------------- */

    public function test_audit_index_scoped_to_own_company(): void
    {
        app(AuditService::class)->log($this->adminA, 'list.a.own', 'employee', $this->workerA->id, null, ['marker' => 'alpha']);
        app(AuditService::class)->log($this->workerUserB, 'list.b.foreign', 'employee', $this->workerB->id, null, ['marker' => 'beta']);

        $this->get('/audit')
            ->assertOk()
            ->assertSee('list.a.own')
            ->assertDontSee('list.b.foreign');

        // подсказка «пользователь» не содержит пользователей чужой компании
        $this->get('/audit?user_id='.$this->workerUserB->id)
            ->assertOk()
            ->assertDontSee('list.b.foreign');
    }

    /* ---------------- табель ---------------- */

    public function test_timesheet_scoped_to_own_company(): void
    {
        // чужой сотрудник в табеле — 404 (без утечки существования)
        $this->get('/attendance/timesheet?employee_id='.$this->workerB->id)->assertNotFound();

        $this->get('/attendance/timesheet?employee_id='.$this->workerA->id)->assertOk();

        // экспорт не выгружает чужих сотрудников
        $this->get('/attendance/timesheet/export?year='.now()->year.'&month='.now()->month.'&employee_id='.$this->workerB->id)
            ->assertOk()
            ->assertDontSee('Бета');
    }

    /* ---------------- посещаемость и аномалии ---------------- */

    public function test_attendance_today_scoped_to_own_company(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->workerA->id,
            'date' => now()->toDateString(),
            'clock_in_at' => now()->setTime(9, 0),
            'status' => 'present',
        ]);
        AttendanceRecord::create([
            'employee_id' => $this->workerB->id,
            'date' => now()->toDateString(),
            'clock_in_at' => now()->setTime(9, 5),
            'status' => 'present',
        ]);

        $this->get('/attendance')
            ->assertOk()
            ->assertSee('Альфа')
            ->assertDontSee('Бета');

        $this->getJson('/attendance/live/feed')
            ->assertOk()
            ->assertJsonMissing(['employee' => 'Бета Сотрудник'])
            ->assertDontSee('Бета');
    }

    public function test_anomalies_index_scoped_to_own_company(): void
    {
        AttendanceAnomaly::create([
            'employee_id' => $this->workerB->id,
            'date' => '2026-11-03',
            'type' => 'no_show',
            'description' => 'Чужая аномалия-маркер',
            'status' => 'open',
        ]);
        AttendanceAnomaly::create([
            'employee_id' => $this->workerA->id,
            'date' => '2026-11-03',
            'type' => 'no_show',
            'description' => 'Своя аномалия-маркер',
            'status' => 'open',
        ]);

        $this->get('/attendance/anomalies')
            ->assertOk()
            ->assertSee('Своя аномалия-маркер')
            ->assertDontSee('Чужая аномалия-маркер');
    }

    /* ---------------- биометрия и RFID ---------------- */

    public function test_biometrics_lists_scoped_to_own_company(): void
    {
        BiometricProfile::create(['employee_id' => $this->workerB->id, 'vendor' => 'generic', 'status' => 'enrolled']);
        BiometricProfile::create(['employee_id' => $this->workerA->id, 'vendor' => 'generic', 'status' => 'enrolled']);

        BiometricEvent::create([
            'device_id' => $this->deviceB->id,
            'employee_id' => $this->workerB->id,
            'event_uuid' => 'evt-b-'.uniqid(),
            'direction' => 'in',
            'raw_payload' => '{"source":"test"}',
            'occurred_at' => now(),
        ]);

        $this->get('/biometrics')
            ->assertOk()
            ->assertSee('TERM-A')
            ->assertDontSee('TERM-B')
            ->assertDontSee('Бета');

        $this->get('/biometrics/events')
            ->assertOk()
            ->assertSee('TERM-A')
            ->assertDontSee('TERM-B');

        $this->get('/biometrics/privacy')
            ->assertOk()
            ->assertDontSee('TERM-B');
    }

    public function test_rfid_lists_scoped_to_own_company(): void
    {
        RfidCard::create([
            'employee_id' => $this->workerA->id,
            'card_uid' => 'UID-A-'.uniqid(),
            'label' => 'Карта Альфа-маркер',
            'status' => 'active',
        ]);
        RfidCard::create([
            'employee_id' => $this->workerB->id,
            'card_uid' => 'UID-B-'.uniqid(),
            'label' => 'Карта Бета-маркер',
            'status' => 'active',
        ]);

        BiometricEvent::create([
            'device_id' => $this->deviceB->id,
            'employee_id' => $this->workerB->id,
            'event_uuid' => 'rfid-b-'.uniqid(),
            'direction' => 'in',
            'template_type' => 'rfid',
            'raw_payload' => '{"source":"test"}',
            'occurred_at' => now(),
        ]);

        $this->get('/rfid')
            ->assertOk()
            ->assertSee('Карта Альфа-маркер')
            ->assertDontSee('Карта Бета-маркер')
            ->assertDontSee('TERM-B');
    }

    /* ---------------- шаблоны и маршруты согласования ---------------- */

    public function test_templates_and_workflows_scoped_to_own_company(): void
    {
        $category = DocumentCategory::create(['code' => 'LST', 'name' => 'Списки']);

        DocumentTemplate::create([
            'company_id' => $this->companyA->id,
            'category_id' => $category->id,
            'name' => 'Шаблон Альфа-маркер',
            'body' => 'Тело',
        ]);
        DocumentTemplate::create([
            'company_id' => $this->companyB->id,
            'category_id' => $category->id,
            'name' => 'Шаблон Бета-маркер',
            'body' => 'Тело',
        ]);

        DocumentWorkflow::create(['company_id' => $this->companyA->id, 'name' => 'Маршрут Альфа-маркер']);
        DocumentWorkflow::create(['company_id' => $this->companyB->id, 'name' => 'Маршрут Бета-маркер']);

        $this->get('/documents/templates')
            ->assertOk()
            ->assertSee('Шаблон Альфа-маркер')
            ->assertDontSee('Шаблон Бета-маркер')
            ->assertDontSee('Маршрут Бета-маркер');

        $this->get('/workflows')
            ->assertOk()
            ->assertSee('Маршрут Альфа-маркер')
            ->assertDontSee('Маршрут Бета-маркер');
    }

    /* ---------------- дашборд ---------------- */

    public function test_dashboard_activity_scoped_to_own_company(): void
    {
        $category = DocumentCategory::create(['code' => 'LSD', 'name' => 'Дашборд']);
        Document::create([
            'company_id' => $this->companyB->id,
            'document_number' => 'DSH-B-'.uniqid(),
            'category_id' => $category->id,
            'title' => 'Чужой документ-маркер',
            'author_id' => $this->workerB->id,
            'status' => 'submitted',
        ]);
        LeaveRequest::create([
            'employee_id' => $this->workerB->id,
            'type' => 'vacation',
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-20',
            'status' => 'pending',
        ]);

        app(AuditService::class)->log($this->workerUserB, 'dashboard.b.foreign', 'employee', $this->workerB->id);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee($this->workerUserB->email)
            ->assertDontSee('dashboard.b.foreign');
    }

    /* ---------------- корректировки: department-scope ---------------- */

    /**
     * Department-scope без собственного отдела не даёт права согласовывать
     * чужие заявки (раньше `null === null` считался совпадением отделов).
     */
    public function test_department_scope_without_department_cannot_approve(): void
    {
        $headUser = User::factory()->create(['email' => 'head@tenant.test']);
        $head = $this->employee($this->companyA, 'LA-9', 'Руководитель', 'БезОтдела', $headUser->id);
        EmployeeRole::create([
            'employee_id' => $head->id,
            'role_id' => Role::query()->where('slug', 'department_director')->value('id'),
        ]);

        $colleague = $this->employee($this->companyA, 'LA-10', 'Коллега', 'БезОтдела');
        $correction = AttendanceCorrection::create([
            'employee_id' => $colleague->id,
            'date' => '2026-11-05',
            'field' => 'clock_in_at',
            'new_value' => '2026-11-05 08:30:00',
            'reason' => 'Проверка department-scope',
            'status' => 'pending',
            'requested_by' => $colleague->id,
        ]);

        $this->actingAs($headUser)
            ->post('/attendance/corrections/'.$correction->id.'/approve')
            ->assertForbidden();
    }

    /* ---------------- задачи, структура, должности ---------------- */

    public function test_task_lists_scoped_to_own_company(): void
    {
        Task::create([
            'company_id' => $this->companyA->id,
            'title' => 'Своя задача-маркер',
            'number' => 'LST-A-'.uniqid(),
            'type' => 'task',
            'status' => 'new',
            'author_id' => $this->adminEmployeeA->id,
        ]);
        Task::create([
            'company_id' => $this->companyB->id,
            'title' => 'Чужая задача-маркер',
            'number' => 'LST-B-'.uniqid(),
            'type' => 'task',
            'status' => 'overdue',
            'deadline' => now()->subDay(),
            'author_id' => $this->workerB->id,
        ]);

        $this->get('/tasks')
            ->assertOk()
            ->assertSee('Своя задача-маркер')
            ->assertDontSee('Чужая задача-маркер');

        $this->get('/tasks/overdue')
            ->assertOk()
            ->assertDontSee('Чужая задача-маркер');
    }

    public function test_departments_orgchart_and_positions_scoped_to_own_company(): void
    {
        Department::create(['company_id' => $this->companyA->id, 'name' => 'Отдел Альфа-маркер', 'code' => 'dep-la']);
        Department::create(['company_id' => $this->companyB->id, 'name' => 'Отдел Бета-маркер', 'code' => 'dep-lb']);
        Position::create(['company_id' => $this->companyA->id, 'name' => 'Должность Альфа-маркер', 'code' => 'pos-la']);
        Position::create(['company_id' => $this->companyB->id, 'name' => 'Должность Бета-маркер', 'code' => 'pos-lb']);

        $this->get('/departments')
            ->assertOk()
            ->assertSee('Отдел Альфа-маркер')
            ->assertDontSee('Отдел Бета-маркер');

        $this->get('/orgchart')
            ->assertOk()
            ->assertSee('Отдел Альфа-маркер')
            ->assertDontSee('Отдел Бета-маркер');

        $this->get('/positions')
            ->assertOk()
            ->assertSee('Должность Альфа-маркер')
            ->assertDontSee('Должность Бета-маркер');
    }
}
