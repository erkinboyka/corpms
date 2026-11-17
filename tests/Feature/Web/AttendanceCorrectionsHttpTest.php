<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Shared\Enums\CorrectionStatus;
use App\Shared\Enums\PermissionScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Корректировки посещаемости (п.28 ТЗ) и экспорт табеля (п.33, 65.5 ТЗ).
 */
class AttendanceCorrectionsHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected WorkSchedule $schedule;
    protected User $adminUser;
    protected Employee $adminEmployee;
    protected User $workerUser;
    protected Employee $worker;
    protected User $peerUser;
    protected Employee $peer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Corr Co', 'code' => 'corco']);
        $this->schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_start' => '13:00:00',
            'break_end' => '14:00:00',
            'grace_minutes' => 10,
        ]);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Руководитель', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Мария', 'Сотрудница', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->peerUser = User::factory()->create();
        $this->peer = $this->employee('W-2', 'Игорь', 'Коллега', $this->peerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->peer->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null, int $schedule = 0): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'work_schedule_id' => $schedule === 0 ? $this->schedule->id : $schedule,
        ]);
    }

    /* -------------------- подача -------------------- */

    public function test_employee_submits_correction_for_own_gap(): void
    {
        $this->actingAs($this->workerUser);

        $response = $this->post('/attendance/corrections', [
            'date' => today()->toDateString(),
            'field' => 'clock_out_at',
            'new_value' => today()->toDateString() . ' 18:05',
            'reason' => 'Забыл отметить уход после смены',
        ]);

        $response->assertRedirect()->assertSessionHas('status');

        /** @var AttendanceCorrection $correction */
        $correction = AttendanceCorrection::query()->sole();
        self::assertSame($this->worker->id, $correction->employee_id);
        self::assertSame('clock_out_at', $correction->field);
        self::assertSame(CorrectionStatus::Pending->value, $correction->status->value);
        self::assertSame($this->worker->id, $correction->requested_by);

        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.correction.requested']);

        // список видит свою заявку
        $this->get('/attendance/corrections')
            ->assertOk()
            ->assertSee('Забыл отметить');
    }

    public function test_second_pending_for_same_field_is_rejected_with_error(): void
    {
        $this->actingAs($this->workerUser);

        $this->post('/attendance/corrections', [
            'date' => today()->toDateString(),
            'field' => 'clock_out_at',
            'new_value' => today()->toDateString() . ' 18:05',
            'reason' => 'Первое',
        ])->assertRedirect();

        $this->from('/attendance/corrections')
            ->post('/attendance/corrections', [
                'date' => today()->toDateString(),
                'field' => 'clock_out_at',
                'new_value' => today()->toDateString() . ' 19:00',
                'reason' => 'Дубль',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['field']);

        self::assertSame(1, AttendanceCorrection::query()->count());
    }

    /* -------------------- согласование -------------------- */

    public function test_approve_updates_timesheet_and_recomputes(): void
    {
        // сотрудник отмечен приходом в 10:05 (опоздание раньше), уход забыт
        $record = AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today()->toDateString(),
            'clock_in_at' => today()->toDateString() . ' 09:20',
            'clock_out_at' => null,
            'late_minutes' => 20,
        ]);

        $this->post('/attendance/corrections', [
            'employee_id' => $this->worker->id,
            'date' => today()->toDateString(),
            'field' => 'clock_out_at',
            'new_value' => today()->toDateString() . ' 18:00',
            'reason' => 'Уход не записался',
        ])->assertRedirect();

        $correction = AttendanceCorrection::query()->sole();

        $this->post('/attendance/corrections/' . $correction->id . '/approve')->assertRedirect();

        $correction->refresh();
        self::assertSame(CorrectionStatus::Approved->value, $correction->status->value);
        self::assertSame($this->adminEmployee->id, $correction->decided_by);
        self::assertNotNull($correction->decided_at);

        // табель обновлён и пересчитан: опоздание 20 мин, отработано 9:20–18:00
        $record->refresh();
        self::assertSame('18:00', $record->clock_out_at->format('H:i'));
        self::assertSame(20, $record->late_minutes);
        self::assertSame($this->adminEmployee->id, $record->corrected_by_employee_id);
        self::assertGreaterThan(300, $record->worked_minutes);

        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.correction.approved']);
    }

    public function test_reject_keeps_timesheet_untouched(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today()->toDateString(),
            'clock_in_at' => today()->toDateString() . ' 09:00',
            'clock_out_at' => today()->toDateString() . ' 18:00',
        ]);

        $this->post('/attendance/corrections', [
            'employee_id' => $this->worker->id,
            'date' => today()->toDateString(),
            'field' => 'clock_in_at',
            'new_value' => today()->toDateString() . ' 08:00',
            'reason' => 'Хочу пораньше в стате',
        ])->assertRedirect();

        $correction = AttendanceCorrection::query()->sole();

        $this->post('/attendance/corrections/' . $correction->id . '/reject', [
            'decision_comment' => 'Отметка прихода верна',
        ])->assertRedirect();

        $correction->refresh();
        self::assertSame(CorrectionStatus::Rejected->value, $correction->status->value);
        self::assertSame('Отметка прихода верна', $correction->decision_comment);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.correction.rejected']);

        self::assertSame('09:00', AttendanceRecord::query()->sole()->clock_in_at->format('H:i'));
    }

    public function test_worker_cannot_approve_and_cannot_edit_others_without_scope(): void
    {
        $correction = AttendanceCorrection::create([
            'employee_id' => $this->peer->id, // чужая заявка для рядового
            'date' => today()->toDateString(),
            'field' => 'clock_in_at',
            'new_value' => today()->toDateString() . ' 08:00',
            'reason' => 'тест',
            'status' => CorrectionStatus::Pending->value,
            'requested_by' => $this->worker->id,
        ]);

        // worker согласовывать не может
        $this->actingAs($this->workerUser);
        $this->post('/attendance/corrections/' . $correction->id . '/approve')->assertForbidden();

        // и чужое согласование (attendance.corrections.approve нет у employee)
        $this->post('/attendance/corrections/' . $correction->id . '/reject', [
            'decision_comment' => 'попытка',
        ])->assertForbidden();

        // но подать заявку за коллегу тоже нельзя (scope own)
        $this->post('/attendance/corrections', [
            'employee_id' => $this->peer->id,
            'date' => today()->toDateString(),
            'field' => 'clock_in_at',
            'new_value' => today()->toDateString() . ' 08:00',
            'reason' => 'за другого',
        ])->assertForbidden();

        self::assertSame(CorrectionStatus::Pending->value, $correction->status->value);
    }

    /* -------------------- уведомление о решении -------------------- */

    public function test_requester_gets_notification_on_decision(): void
    {
        $this->post('/attendance/corrections', [
            'employee_id' => $this->worker->id,
            'date' => today()->toDateString(),
            'field' => 'clock_in_at',
            'new_value' => today()->toDateString() . ' 08:00',
            'reason' => 'ранее заявлено',
        ])->assertRedirect();

        $correctionId = AttendanceCorrection::query()->sole()->id;
        $requestedBy = AttendanceCorrection::query()->sole()->requested_by; // admin (себя подавал)

        $this->post('/attendance/corrections/' . $correctionId . '/approve')->assertRedirect();

        $this->assertDatabaseHas('attendance_notifications', [
            'recipient_id' => $requestedBy,
            'type' => 'attendance.correction.decided',
        ]);
    }

    /* -------------------- экспорт табеля -------------------- */

    public function test_timesheet_export_csv_with_bom_and_audit(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => now()->startOfMonth()->toDateString(),
            'clock_in_at' => now()->startOfMonth()->toDateString() . ' 09:00',
            'clock_out_at' => now()->startOfMonth()->toDateString() . ' 18:00',
            'worked_minutes' => 480,
        ]);

        $response = $this->get('/attendance/timesheet/export?year=' . now()->year . '&month=' . now()->month);
        $response->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Мария', $csv);
        $this->assertStringContainsString('09:00–18:00', $csv);

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_timesheet_export_scope_and_denied_for_bare_user(): void
    {
        // сотрудник без роли вообще — права нет
        $bareUser = User::factory()->create();
        $this->employee('B-1', 'Борис', 'Безправный', $bareUser->id);
        $this->actingAs($bareUser);
        $this->get('/attendance/timesheet/export')->assertForbidden();

        // обычный сотрудник может выгрузить свой табель (scope own)
        $this->actingAs($this->workerUser);
        $this->get('/attendance/timesheet/export?year=' . now()->year . '&month=' . now()->month)->assertOk();
    }
}
