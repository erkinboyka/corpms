<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tasks\Services\TaskService;
use App\Models\AppNotification;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceCorrection;
use App\Models\BiometricDevice;
use App\Models\BiometricProfile;
use App\Models\Company;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentSignatureRequest;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\RfidCard;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Shared\Enums\AssigneeType;
use App\Shared\Enums\TaskAssignmentStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Фаза A, задача 1 (tenant-guard): межтенантная изоляция на HTTP-уровне.
 *
 * Пользователь из компании A не должен читать/изменять ресурсы компании B,
 * даже будучи super_admin в своей компании (P0 из аудита: IDOR между тенантами).
 */
class TenantIsolationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected Employee $adminEmployeeA;

    protected Employee $workerA;

    protected User $workerUserA;

    protected Employee $workerB;

    protected User $workerUserB;

    protected WorkSchedule $scheduleA;

    protected WorkSchedule $scheduleB;

    protected Holiday $holidayA;

    protected Holiday $holidayB;

    protected PayrollPeriod $periodA;

    protected PayrollPeriod $periodB;

    protected Document $documentA;

    protected Document $documentB;

    protected LeaveRequest $leaveA;

    protected LeaveRequest $leaveB;

    protected Department $departmentA;

    protected Department $departmentB;

    protected Employee $orphanEmployeeA;

    protected Task $taskA;

    protected Task $taskB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->companyA = Company::create(['name' => 'Tenant A', 'code' => 'tenc-a']);
        $this->companyB = Company::create(['name' => 'Tenant B', 'code' => 'tenc-b']);

        $superAdminRoleId = Role::query()->where('slug', 'super_admin')->value('id');

        // админ компании A — aktor всех запросов в тестах
        $this->adminA = User::factory()->create(['email' => 'admin-a@tenant.test']);
        $this->adminEmployeeA = $this->employee($this->companyA, 'AA-1', 'Админ', 'А', $this->adminA->id);
        EmployeeRole::create(['employee_id' => $this->adminEmployeeA->id, 'role_id' => $superAdminRoleId]);

        // сотрудники обеих компаний (с привязанными учётками)
        $this->workerA = $this->employee($this->companyA, 'WA-1', 'Работник', 'А');
        $this->workerUserA = User::findOrFail($this->workerA->user_id);

        $this->workerB = $this->employee($this->companyB, 'WB-1', 'Работник', 'Б');
        $this->workerUserB = User::findOrFail($this->workerB->user_id);

        // сотрудник компании A без учётки — для позитивного теста привязки
        $this->orphanEmployeeA = $this->employee($this->companyA, 'WA-2', 'Без', 'Учётки', null);
        $this->orphanEmployeeA->update(['user_id' => null]);

        $this->departmentA = Department::create(['company_id' => $this->companyA->id, 'name' => 'Отдел А', 'code' => 'dep-a']);
        $this->departmentB = Department::create(['company_id' => $this->companyB->id, 'name' => 'Отдел Б', 'code' => 'dep-b']);

        $this->scheduleA = $this->schedule($this->companyA, 'График А');
        $this->scheduleB = $this->schedule($this->companyB, 'График Б');

        $this->holidayA = Holiday::create([
            'company_id' => $this->companyA->id,
            'name' => 'Праздник А',
            'date' => '2026-05-01',
            'kind' => 'public',
            'is_day_off' => true,
        ]);
        $this->holidayB = Holiday::create([
            'company_id' => $this->companyB->id,
            'name' => 'Праздник Б',
            'date' => '2026-05-02',
            'kind' => 'public',
            'is_day_off' => true,
        ]);

        $this->periodA = $this->period($this->companyA, '2026-09-01', '2026-09-30');
        $this->periodB = $this->period($this->companyB, '2026-09-01', '2026-09-30');

        $category = DocumentCategory::create(['code' => 'TEN', 'name' => 'Межтенантные']);
        $this->documentA = $this->document($this->companyA, $this->adminEmployeeA, $category, 'Док А');
        $this->documentB = $this->document($this->companyB, $this->workerB, $category, 'Док Б');

        $this->leaveA = $this->leave($this->workerA, '2026-11-10', '2026-11-12');
        $this->leaveB = $this->leave($this->workerB, '2026-11-20', '2026-11-22');

        $this->taskA = Task::create([
            'company_id' => $this->companyA->id,
            'title' => 'сводка Алфа',
            'number' => 'TEN-A-'.uniqid(),
            'type' => 'task',
            'status' => 'new',
            'author_id' => $this->adminEmployeeA->id,
        ]);
        $this->taskB = Task::create([
            'company_id' => $this->companyB->id,
            'title' => 'сводка Бета',
            'number' => 'TEN-B-'.uniqid(),
            'type' => 'task',
            'status' => 'new',
            'author_id' => $this->workerB->id,
        ]);

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

    protected function schedule(Company $company, string $name): WorkSchedule
    {
        return WorkSchedule::create([
            'company_id' => $company->id,
            'name' => $name,
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'grace_minutes' => 10,
        ]);
    }

    protected function period(Company $company, string $start, string $end): PayrollPeriod
    {
        return PayrollPeriod::create([
            'company_id' => $company->id,
            'period_start' => $start,
            'period_end' => $end,
            'status' => 'draft',
        ]);
    }

    protected function document(Company $company, Employee $author, DocumentCategory $category, string $title): Document
    {
        return Document::create([
            'company_id' => $company->id,
            'document_number' => 'TEN-'.uniqid(),
            'category_id' => $category->id,
            'title' => $title,
            'author_id' => $author->id,
            'status' => 'draft',
        ]);
    }

    protected function leave(Employee $employee, string $from, string $to): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'type' => 'vacation',
            'from_date' => $from,
            'to_date' => $to,
            'status' => 'pending',
        ]);
    }

    /* ---------------- ведомость ---------------- */

    public function test_payroll_cross_tenant_blocked(): void
    {
        $this->get('/payroll/periods/'.$this->periodB->id)->assertForbidden();
        $this->post('/payroll/periods/'.$this->periodB->id.'/calculate')->assertForbidden();
        $this->post('/payroll/periods/'.$this->periodB->id.'/approve')->assertForbidden();
        $this->post('/payroll/periods/'.$this->periodB->id.'/pay')->assertForbidden();
        $this->get('/payroll/periods/'.$this->periodB->id.'/export')->assertForbidden();

        // своя ведомость доступна
        $this->get('/payroll/periods/'.$this->periodA->id)->assertOk();
    }

    /* ---------------- сотрудники ---------------- */

    public function test_employee_card_cross_tenant_blocked(): void
    {
        $this->get('/employees/'.$this->workerB->id)->assertForbidden();
        $this->patch('/employees/'.$this->workerB->id, [
            'first_name' => 'Чужой',
            'last_name' => 'Пользователь',
            'employee_no' => 'WB-1',
            'status' => 'active',
        ])->assertForbidden();
        $this->put('/employees/'.$this->workerB->id.'/roles', ['role_ids' => []])->assertForbidden();
        $this->get('/employees/'.$this->workerB->id.'/subordinates')->assertForbidden();

        // своя карточка доступна
        $this->get('/employees/'.$this->workerA->id)->assertOk();
    }

    public function test_employee_update_foreign_reference_blocked(): void
    {
        // чужой отдел в форме обновления своего сотрудника — 403 (анти-перенос)
        $this->patch('/employees/'.$this->workerA->id, [
            'first_name' => 'Работник',
            'last_name' => 'А',
            'employee_no' => 'WA-1',
            'status' => 'active',
            'department_id' => $this->departmentB->id,
        ])->assertForbidden();

        // свой отдел — проходит
        $this->patch('/employees/'.$this->workerA->id, [
            'first_name' => 'Работник',
            'last_name' => 'А',
            'employee_no' => 'WA-1',
            'status' => 'active',
            'department_id' => $this->departmentA->id,
        ])->assertRedirect()->assertSessionHas('status');
    }

    /* ---------------- документы ---------------- */

    public function test_document_card_cross_tenant_blocked(): void
    {
        $this->get('/documents/'.$this->documentB->id)->assertForbidden();
        $this->post('/documents/'.$this->documentB->id.'/submit')->assertForbidden();
        $this->post('/documents/'.$this->documentB->id.'/decide', [
            'decision' => 'approved',
        ])->assertForbidden();
        $this->post('/documents/'.$this->documentB->id.'/archive')->assertForbidden();

        $this->get('/documents/'.$this->documentA->id)->assertOk();
    }

    public function test_document_sign_request_foreign_signer_blocked(): void
    {
        // подписант из другой компании — 403
        $this->post('/documents/'.$this->documentA->id.'/signatures/request', [
            'signer_ids' => [$this->workerB->id],
        ])->assertForbidden();

        // подписант своей компании — операция доходит до сервиса (redirect со статусом/ошибкой бизнеса, но не 403)
        $response = $this->post('/documents/'.$this->documentA->id.'/signatures/request', [
            'signer_ids' => [$this->workerA->id],
        ]);
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /* ---------------- учётные записи ---------------- */

    public function test_password_reset_cross_tenant_blocked(): void
    {
        $this->post('/users/'.$this->workerUserB->id.'/password', [
            'password' => 'new-secret-123',
        ])->assertForbidden();

        // своя учётка — сброс проходит (политика паролей: 12+ символов)
        $this->post('/users/'.$this->workerUserA->id.'/password', [
            'password' => 'new-secret-123',
        ])->assertRedirect()->assertSessionHas('status');
    }

    public function test_user_store_foreign_employee_blocked(): void
    {
        $this->post('/users', [
            'name' => 'Чужой',
            'email' => 'foreign-link@tenant.test',
            'employee_id' => $this->workerB->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'foreign-link@tenant.test']);

        // привязка к сотруднику своей компании без учётки — проходит
        $orphan = $this->orphanEmployeeA;

        $this->post('/users', [
            'name' => 'Свой',
            'email' => 'own-link@tenant.test',
            'employee_id' => $orphan->id,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(User::where('email', 'own-link@tenant.test')->value('id'), $orphan->refresh()->user_id);
    }

    /* ---------------- графики и праздники ---------------- */

    public function test_schedule_crud_cross_tenant_blocked(): void
    {
        $this->patch('/schedules/'.$this->scheduleB->id, [
            'name' => 'Взлом',
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ])->assertForbidden();
        $this->delete('/schedules/'.$this->scheduleB->id)->assertForbidden();

        $this->patch('/schedules/'.$this->scheduleA->id, [
            'name' => 'График А2',
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ])->assertRedirect()->assertSessionHas('status');
    }

    public function test_schedule_assignment_cross_tenant_blocked(): void
    {
        // чужой сотрудник — 403
        $this->post('/schedules/assignments', [
            'work_schedule_id' => $this->scheduleA->id,
            'employee_ids' => [$this->workerB->id],
            'effective_from' => '2026-11-01',
        ])->assertForbidden();

        // чужой график — 403
        $this->post('/schedules/assignments', [
            'work_schedule_id' => $this->scheduleB->id,
            'employee_ids' => [$this->workerA->id],
            'effective_from' => '2026-11-01',
        ])->assertForbidden();
    }

    public function test_holiday_cross_tenant_blocked(): void
    {
        $this->delete('/holidays/'.$this->holidayB->id)->assertForbidden();

        $this->delete('/holidays/'.$this->holidayA->id)->assertRedirect()->assertSessionHas('status');
    }

    /* ---------------- отпуска ---------------- */

    public function test_leave_decision_cross_tenant_blocked(): void
    {
        $this->put('/leaves/'.$this->leaveB->id.'/decision', ['decision' => 'approved'])->assertForbidden();

        $this->put('/leaves/'.$this->leaveA->id.'/decision', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_leave_request_for_foreign_employee_blocked(): void
    {
        $this->post('/leaves', [
            'employee_id' => $this->workerB->id,
            'type' => 'vacation',
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-05',
        ])->assertForbidden();
    }

    /* ---------------- списки ---------------- */

    public function test_indexes_show_only_own_company(): void
    {
        $this->get('/schedules')
            ->assertOk()
            ->assertSee('График А')
            ->assertDontSee('График Б');

        $this->get('/holidays')
            ->assertOk()
            ->assertSee('Праздник А')
            ->assertDontSee('Праздник Б');

        $this->get('/users')
            ->assertOk()
            ->assertSee($this->workerUserA->email)
            ->assertDontSee($this->workerUserB->email);

        $this->get('/documents')
            ->assertOk()
            ->assertSee('Док А')
            ->assertDontSee('Док Б');
    }

    /* ---------------- задачи и поиск (задача 2: company-scope) ---------------- */

    public function test_task_card_cross_tenant_blocked(): void
    {
        // company-scope super_admin не должен видеть чужую задачу (canSeeTask)
        $this->get('/tasks/'.$this->taskB->id)->assertForbidden();
        $this->patch('/tasks/'.$this->taskB->id, ['title' => 'Взлом'])->assertForbidden();
        $this->put('/tasks/'.$this->taskB->id.'/status', ['status' => 'completed'])->assertForbidden();

        $this->get('/tasks/'.$this->taskA->id)->assertOk();
    }

    public function test_search_scoped_to_own_company(): void
    {
        $this->get('/search?q=сводка')
            ->assertOk()
            ->assertSee('сводка Алфа')
            ->assertDontSee('сводка Бета');

        $this->get('/search?q=Док')
            ->assertOk()
            ->assertSee('Док А')
            ->assertDontSee('Док Б');

        // JSON-подсказки: сверяем декодированный payload (экранирование Unicode)
        $this->getJson('/search/hint?q=сводка')
            ->assertOk()
            ->assertJsonFragment(['title' => 'сводка Алфа'])
            ->assertJsonMissing(['title' => 'сводка Бета']);
    }

    public function test_ai_panel_search_scoped_to_own_company(): void
    {
        $this->postJson('/ai/message', ['question' => 'найди сводка'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['label' => 'Задача: сводка Алфа'])
            ->assertJsonMissing(['label' => 'Задача: сводка Бета']);
    }

    /* ---------------- корректировки табеля ---------------- */

    public function test_attendance_correction_cross_tenant_blocked(): void
    {
        $correctionB = AttendanceCorrection::create([
            'employee_id' => $this->workerB->id,
            'date' => '2026-11-05',
            'field' => 'clock_in_at',
            'old_value' => '2026-11-05 09:15:00',
            'new_value' => '2026-11-05 08:45:00',
            'reason' => 'Опоздал по техническим причинам',
            'status' => 'pending',
            'requested_by' => $this->workerB->id,
        ]);

        $this->post('/attendance/corrections/'.$correctionB->id.'/approve')->assertForbidden();
        $this->post('/attendance/corrections/'.$correctionB->id.'/reject', [
            'decision_comment' => 'Чужая компания',
        ])->assertForbidden();

        // подача корректировки на чужого сотрудника — 403
        $this->post('/attendance/corrections', [
            'employee_id' => $this->workerB->id,
            'date' => '2026-11-06',
            'field' => 'clock_in_at',
            'new_value' => '2026-11-06 08:30:00',
            'reason' => 'Перенос отметки',
        ])->assertForbidden();

        // своя корректировка — согласование доходит до сервиса (не 403)
        $correctionA = AttendanceCorrection::create([
            'employee_id' => $this->workerA->id,
            'date' => '2026-11-05',
            'field' => 'clock_in_at',
            'old_value' => '2026-11-05 09:15:00',
            'new_value' => '2026-11-05 08:45:00',
            'reason' => 'Опоздал по техническим причинам',
            'status' => 'pending',
            'requested_by' => $this->workerA->id,
        ]);

        $response = $this->post('/attendance/corrections/'.$correctionA->id.'/approve');
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /* ---------------- отделы и должности (S1: binding-точка) ---------------- */

    public function test_department_crud_cross_tenant_blocked(): void
    {
        $this->patch('/departments/'.$this->departmentB->id, [
            'name' => 'Взлом',
            'code' => 'dep-b',
        ])->assertForbidden();
        $this->delete('/departments/'.$this->departmentB->id)->assertForbidden();

        $this->patch('/departments/'.$this->departmentA->id, [
            'name' => 'Отдел А2',
            'code' => 'dep-a',
        ])->assertRedirect()->assertSessionHas('status');
    }

    public function test_position_crud_cross_tenant_blocked(): void
    {
        $positionA = Position::create(['company_id' => $this->companyA->id, 'name' => 'Инженер А', 'code' => 'ENG-A', 'grade' => 'S1']);
        $positionB = Position::create(['company_id' => $this->companyB->id, 'name' => 'Инженер Б', 'code' => 'ENG-B', 'grade' => 'S1']);

        $this->patch('/positions/'.$positionB->id, ['name' => 'Взлом', 'code' => 'ENG-B'])->assertForbidden();
        $this->delete('/positions/'.$positionB->id)->assertForbidden();

        $this->patch('/positions/'.$positionA->id, ['name' => 'Инженер А2', 'code' => 'ENG-A'])
            ->assertRedirect()->assertSessionHas('status');
    }

    /* ---------------- справочник компаний (документированное исключение S1) ---------------- */

    public function test_company_registry_is_platform_function(): void
    {
        $foreign = Company::create(['name' => 'Чужой тенант', 'code' => 'foreign-tenant']);

        // super_admin — платформенная роль с companies.manage: справочник
        // тенант-сущностей глобален, иначе ветка «удалить пустую компанию»
        // недостижима (админ всегда сотрудник какого-либо тенанта).
        $this->patch('/companies/'.$foreign->id, [
            'name' => 'Чужой тенант 2',
            'code' => 'foreign-tenant',
        ])->assertRedirect()->assertSessionHas('status');

        // при этом данные внутри чужого тенанта закрыты для той же роли
        $this->get('/employees/'.$this->workerB->id)->assertForbidden();
        $this->get('/documents/'.$this->documentB->id)->assertForbidden();
        $this->get('/tasks/'.$this->taskB->id)->assertForbidden();
    }

    /* ---------------- аномалии табеля ---------------- */

    public function test_anomaly_resolution_cross_tenant_blocked(): void
    {
        $anomalyB = AttendanceAnomaly::create([
            'employee_id' => $this->workerB->id,
            'date' => '2026-11-04',
            'type' => 'no_show',
            'status' => 'open',
        ]);
        $anomalyA = AttendanceAnomaly::create([
            'employee_id' => $this->workerA->id,
            'date' => '2026-11-04',
            'type' => 'no_show',
            'status' => 'open',
        ]);

        $this->put('/attendance/anomalies/'.$anomalyB->id, ['resolution' => 'Чужая'])
            ->assertForbidden();

        $response = $this->put('/attendance/anomalies/'.$anomalyA->id, ['resolution' => 'Своя']);
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /* ---------------- биометрия ---------------- */

    public function test_biometric_device_cross_tenant_blocked(): void
    {
        $deviceB = $this->device($this->companyB, 'SN-B');
        $deviceA = $this->device($this->companyA, 'SN-A');

        $this->post('/biometrics/devices/'.$deviceB->id, ['status' => 'online'])->assertForbidden();
        $this->post('/biometrics/devices/'.$deviceB->id.'/delete')->assertForbidden();

        $this->post('/biometrics/devices/'.$deviceA->id, ['status' => 'online'])
            ->assertRedirect()->assertSessionHas('status');
    }

    public function test_biometric_profile_cross_tenant_blocked(): void
    {
        $profileB = BiometricProfile::create([
            'employee_id' => $this->workerB->id,
            'vendor' => 'generic',
            'status' => 'enrolled',
        ]);
        $profileA = BiometricProfile::create([
            'employee_id' => $this->workerA->id,
            'vendor' => 'generic',
            'status' => 'enrolled',
        ]);

        $this->post('/biometrics/profiles/'.$profileB->id.'/disable')->assertForbidden();
        $this->post('/biometrics/profiles/'.$profileB->id.'/delete')->assertForbidden();

        $response = $this->post('/biometrics/profiles/'.$profileA->id.'/disable');
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /* ---------------- RFID ---------------- */

    public function test_rfid_revoke_cross_tenant_blocked(): void
    {
        $cardB = RfidCard::create([
            'employee_id' => $this->workerB->id,
            'card_uid' => 'RFID-B-'.uniqid(),
            'status' => 'active',
        ]);
        $cardA = RfidCard::create([
            'employee_id' => $this->workerA->id,
            'card_uid' => 'RFID-A-'.uniqid(),
            'status' => 'active',
        ]);

        $this->post('/rfid/'.$cardB->id.'/revoke')->assertForbidden();

        $response = $this->post('/rfid/'.$cardA->id.'/revoke');
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /* ---------------- уведомления ---------------- */

    public function test_notification_mark_read_cross_tenant_blocked(): void
    {
        $notificationB = AppNotification::create([
            'recipient_id' => $this->workerB->id,
            'type' => 'attendance.missing_checkout',
            'title' => 'Чужое уведомление',
        ]);
        $notificationA = AppNotification::create([
            'recipient_id' => $this->adminEmployeeA->id,
            'type' => 'attendance.missing_checkout',
            'title' => 'Своё уведомление',
        ]);

        $this->put('/notifications/'.$notificationB->id.'/read')->assertForbidden();

        // уведомление другого сотрудника (своей же компании) — тоже 403, не 500
        $notificationColleague = AppNotification::create([
            'recipient_id' => $this->workerA->id,
            'type' => 'attendance.missing_checkout',
            'title' => 'Коллеге',
        ]);
        $this->put('/notifications/'.$notificationColleague->id.'/read')->assertForbidden();

        $this->put('/notifications/'.$notificationA->id.'/read')
            ->assertRedirect();
    }

    /* ---------------- вложения задач ---------------- */

    public function test_task_attachment_cross_tenant_blocked(): void
    {
        $attachmentB = TaskAttachment::create([
            'task_id' => $this->taskB->id,
            'employee_id' => $this->workerB->id,
            'file_path' => 'tasks/foreign.txt',
            'original_name' => 'foreign.txt',
        ]);

        // чужая задача — 403 на любом уровне
        $this->get('/tasks/'.$this->taskB->id.'/attachments/'.$attachmentB->id)->assertForbidden();
        $this->delete('/tasks/'.$this->taskB->id.'/attachments/'.$attachmentB->id)->assertForbidden();

        // дочерний объект не принадлежит родителю из маршрута — 404 (без утечки)
        $this->get('/tasks/'.$this->taskA->id.'/attachments/'.$attachmentB->id)->assertNotFound();
        $this->delete('/tasks/'.$this->taskA->id.'/attachments/'.$attachmentB->id)->assertNotFound();
    }

    /* ---------------- шаблоны и подписи документов ---------------- */

    public function test_document_template_cross_tenant_blocked(): void
    {
        $category = DocumentCategory::query()->where('code', 'TEN')->firstOrFail();
        $templateB = DocumentTemplate::create([
            'company_id' => $this->companyB->id,
            'category_id' => $category->id,
            'name' => 'Чужой шаблон',
            'body' => 'Тело шаблона',
        ]);
        $templateA = DocumentTemplate::create([
            'company_id' => $this->companyA->id,
            'category_id' => $category->id,
            'name' => 'Свой шаблон',
            'body' => 'Тело шаблона',
        ]);

        $this->post('/documents/templates/'.$templateB->id.'/create')->assertForbidden();

        $response = $this->post('/documents/templates/'.$templateA->id.'/create');
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_document_signature_request_cross_tenant_blocked(): void
    {
        $versionB = DocumentVersion::create([
            'document_id' => $this->documentB->id,
            'version' => 1,
            'sha256' => hash('sha256', 'tenant-sign'),
        ]);
        $requestB = DocumentSignatureRequest::create([
            'document_id' => $this->documentB->id,
            'document_version_id' => $versionB->id,
            'signer_id' => $this->workerB->id,
            'requested_by' => $this->workerB->id,
            'status' => 'pending',
        ]);

        // чужой документ — 403
        $this->post('/documents/'.$this->documentB->id.'/signatures/'.$requestB->id.'/decline', [
            'reason' => 'Чужой документ',
        ])->assertForbidden();

        // запрос на подпись не принадлежит документу из маршрута — 404
        $this->post('/documents/'.$this->documentA->id.'/signatures/'.$requestB->id.'/decline', [
            'reason' => 'Подмена',
        ])->assertNotFound();
    }

    /* ---------- кросс-тенантная запись через тело запроса (спринт после аудита) ---------- */

    public function test_task_store_with_foreign_assignee_blocked(): void
    {
        $this->post('/tasks', [
            'title' => 'Чужое назначение',
            'type' => 'task',
            'priority' => 'medium',
            'employee_ids' => [$this->workerA->id, $this->workerB->id],
        ])->assertSessionHasErrors('employee_ids');

        $this->assertDatabaseMissing('tasks', ['title' => 'Чужое назначение']);
    }

    public function test_task_store_with_foreign_department_blocked(): void
    {
        $this->post('/tasks', [
            'title' => 'Назначение чужому отделу',
            'type' => 'task',
            'priority' => 'medium',
            'department_ids' => [$this->departmentB->id],
        ])->assertSessionHasErrors('department_ids');

        $this->assertDatabaseMissing('tasks', ['title' => 'Назначение чужому отделу']);
    }

    public function test_task_bulk_assignment_to_foreign_employees_blocked(): void
    {
        $this->post('/tasks/'.$this->taskA->id.'/bulk', [
            'employee_ids' => [$this->workerA->id, $this->workerB->id],
        ])->assertSessionHasErrors('employee_ids');

        $this->assertDatabaseMissing('tasks', ['parent_id' => $this->taskA->id]);
    }

    public function test_task_subtask_for_foreign_employee_blocked(): void
    {
        $this->post('/tasks/'.$this->taskA->id.'/subtasks', [
            'title' => 'Чужая подзадача',
            'priority' => 'medium',
            'employee_id' => $this->workerB->id,
        ])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseMissing('tasks', ['title' => 'Чужая подзадача']);
    }

    public function test_task_delegate_to_foreign_employee_blocked(): void
    {
        $assignment = TaskAssignment::create([
            'task_id' => $this->taskA->id,
            'assignee_type' => AssigneeType::Employee,
            'employee_id' => $this->adminEmployeeA->id,
            'assignment_status' => TaskAssignmentStatus::Pending,
        ]);

        $this->post('/tasks/'.$this->taskA->id.'/delegate', [
            'assignment_id' => $assignment->id,
            'to_employee_id' => $this->workerB->id,
        ])->assertSessionHasErrors('to_employee_id');

        // подмена задания чужой задачи через свой assignment — 404
        $foreignAssignment = TaskAssignment::create([
            'task_id' => $this->taskB->id,
            'assignee_type' => AssigneeType::Employee,
            'employee_id' => $this->adminEmployeeA->id,
            'assignment_status' => TaskAssignmentStatus::Pending,
        ]);
        $this->post('/tasks/'.$this->taskA->id.'/delegate', [
            'assignment_id' => $foreignAssignment->id,
            'to_employee_id' => $this->workerA->id,
        ])->assertNotFound();
    }

    public function test_task_numbering_is_scoped_per_company(): void
    {
        $service = app(TaskService::class);
        $year = now()->format('Y');

        // в A одна задача из setUp (+2 → 3), в B одна → нумерация не «глобальная»
        foreach ([1, 2] as $i) {
            Task::create([
                'company_id' => $this->companyA->id,
                'title' => 'Нумерация А-'.$i,
                'number' => 'NMX-A-'.$i.uniqid(),
                'type' => 'task',
                'status' => 'new',
                'author_id' => $this->adminEmployeeA->id,
            ]);
        }

        $this->assertSame(sprintf('TSK-%s-000004', $year), $service->nextNumber($this->companyA->id));
        $this->assertSame(sprintf('TSK-%s-000002', $year), $service->nextNumber($this->companyB->id));
    }

    public function test_biometric_enroll_foreign_employee_and_device_blocked(): void
    {
        $deviceA = $this->device($this->companyA, 'TERM-ISO-A');

        // чужой сотрудник → 403
        $this->post('/biometrics/profiles', [
            'employee_id' => $this->workerB->id,
            'device_id' => $deviceA->id,
            'vendor' => 'generic',
            'template_hash' => hash('sha256', 'tpl'),
        ])->assertForbidden();

        // чужое устройство → 403
        $deviceB = $this->device($this->companyB, 'TERM-ISO-B');
        $this->post('/biometrics/profiles', [
            'employee_id' => $this->workerA->id,
            'device_id' => $deviceB->id,
            'vendor' => 'generic',
            'template_hash' => hash('sha256', 'tpl'),
        ])->assertForbidden();

        $this->assertDatabaseMissing('biometric_profiles', ['employee_id' => $this->workerB->id]);
        $this->assertDatabaseMissing('biometric_profiles', ['employee_id' => $this->workerA->id]);
    }

    public function test_department_store_and_update_foreign_references_blocked(): void
    {
        // создание отдела с чужим руководителем → 403
        $this->post('/departments', [
            'name' => 'Чужой директор',
            'code' => 'dep-fdir',
            'director_id' => $this->workerB->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('departments', ['code' => 'dep-fdir']);

        // создание отдела с чужим родителем → 403
        $this->post('/departments', [
            'name' => 'Чужой родитель',
            'code' => 'dep-fpar',
            'parent_id' => $this->departmentB->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('departments', ['code' => 'dep-fpar']);

        // перенос своего отдела под чужой → 403
        $this->patch('/departments/'.$this->departmentA->id, [
            'name' => $this->departmentA->name,
            'parent_id' => $this->departmentB->id,
        ])->assertForbidden();
        $this->assertSame(0, (int) Department::query()->where('id', $this->departmentA->id)->value('parent_id'));
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
}
