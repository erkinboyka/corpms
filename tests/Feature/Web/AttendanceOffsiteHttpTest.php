<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AttendanceOffsiteRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceStatusHistory;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\FaceVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Shared\Enums\OffsiteRequestStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Offsite-поток (п. 27–29 ТЗ):
 * PENDING не даёт присутствия, APPROVED даёт PRESENT/OFFSITE без face.
 */
class AttendanceOffsiteHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected WorkSchedule $schedule;

    protected User $directorUser;

    protected Employee $director;

    protected User $workerUser;

    protected Employee $worker;

    protected User $peerUser;

    protected Employee $peer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Offsite Co', 'code' => 'offsiteco']);
        $this->schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);

        $this->directorUser = User::factory()->create();
        $this->director = $this->employee('D-1', 'Иван', 'Директор', $this->directorUser->id);
        $this->director->update(['manager_id' => null]);
        EmployeeRole::create([
            'employee_id' => $this->director->id,
            'role_id' => Role::query()->where('slug', 'company_director')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Мария', 'Сотрудница', $this->workerUser->id);
        $this->worker->update(['manager_id' => $this->director->id]);
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

        $this->actingAs($this->directorUser);
    }

    protected function employee(string $no, string $first, string $last, int $userId): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'work_schedule_id' => $this->schedule->id,
        ]);
    }

    public function test_pending_request_does_not_create_presence(): void
    {
        $this->actingAs($this->workerUser);

        $this->post('/attendance/offsite', [
            'date' => today()->toDateString(),
            'reason' => 'Работа по договору вне офиса',
        ])->assertRedirect()->assertSessionHas('status');

        /** @var AttendanceOffsiteRequest $request */
        $request = AttendanceOffsiteRequest::query()->sole();
        self::assertSame(OffsiteRequestStatus::Pending->value, $request->status->value);
        self::assertSame($this->worker->id, $request->employee_id);
        self::assertSame(0, AttendanceRecord::query()->count(), 'PENDING не должен создавать присутствие');
        self::assertSame(0, AttendanceStatusHistory::query()->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendance.offsite.requested',
            'company_id' => $this->company->id,
        ]);
        $this->assertDatabaseHas('attendance_notifications', [
            'recipient_id' => $this->director->id,
            'type' => 'attendance.offsite.requested',
        ]);
    }

    public function test_approval_marks_present_offsite_without_face_and_writes_history(): void
    {
        $this->actingAs($this->workerUser);
        $this->post('/attendance/offsite', [
            'date' => today()->toDateString(),
            'reason' => 'Удалённая работа по согласованию',
        ])->assertRedirect();

        $request = AttendanceOffsiteRequest::query()->sole();

        $this->actingAs($this->directorUser);
        $this->post('/attendance/offsite/'.$request->id.'/approve')->assertRedirect();

        $request->refresh();
        self::assertSame(OffsiteRequestStatus::Approved->value, $request->status->value);
        self::assertSame($this->director->id, $request->decided_by);

        $record = AttendanceRecord::query()->sole();
        self::assertSame('present', $record->status->value);
        self::assertSame('offsite', $record->work_type);
        self::assertSame('offsite_request', $record->source->value);
        self::assertSame($this->director->id, $record->approved_by);
        self::assertNull($record->clock_in_at, 'face-приход не требуется');

        self::assertSame(1, AttendanceStatusHistory::query()->count());
        self::assertSame('present', AttendanceStatusHistory::query()->sole()->to_status);
        self::assertSame('offsite', AttendanceStatusHistory::query()->sole()->work_type);
        self::assertDatabaseHas('face_verification_logs', ['result' => 'skipped_offsite']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.offsite.approved']);
        $this->assertDatabaseHas('attendance_notifications', [
            'recipient_id' => $this->worker->id,
            'type' => 'attendance.offsite.approved',
        ]);
    }

    public function test_rejection_keeps_timesheet_untouched(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today()->toDateString(),
            'clock_in_at' => today()->toDateString().' 09:00',
            'clock_out_at' => today()->toDateString().' 18:00',
        ]);

        $this->actingAs($this->workerUser);
        $this->post('/attendance/offsite', [
            'date' => today()->toDateString(),
            'reason' => 'Хочу работать из дома',
        ])->assertRedirect();

        $request = AttendanceOffsiteRequest::query()->sole();

        $this->actingAs($this->directorUser);
        $this->post('/attendance/offsite/'.$request->id.'/reject', [
            'decision_comment' => 'Нужен очный день',
        ])->assertRedirect();

        self::assertSame(OffsiteRequestStatus::Rejected->value, $request->fresh()->status->value);
        $record = AttendanceRecord::query()->sole();
        self::assertSame('office', $record->work_type);
        self::assertNull($record->approved_by);
        self::assertSame(0, AttendanceStatusHistory::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.offsite.rejected']);
    }

    public function test_self_approval_is_forbidden(): void
    {
        // заявитель сам обладает правом согласующего — само-согласование всё равно блокируется
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'company_director')->value('id'),
        ]);

        $this->actingAs($this->workerUser);
        $this->post('/attendance/offsite', [
            'date' => today()->toDateString(),
            'reason' => 'Сам себе руководитель',
        ])->assertRedirect();

        $request = AttendanceOffsiteRequest::query()->sole();
        $this->from('/attendance/offsite')
            ->post('/attendance/offsite/'.$request->id.'/approve')
            ->assertRedirect('/attendance/offsite')
            ->assertSessionHasErrors('reason');

        self::assertSame(OffsiteRequestStatus::Pending->value, $request->fresh()->status->value);
        self::assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_double_decision_is_rejected(): void
    {
        $this->actingAs($this->workerUser);
        $this->post('/attendance/offsite', [
            'date' => today()->toDateString(),
            'reason' => 'Повторное согласование',
        ])->assertRedirect();

        $request = AttendanceOffsiteRequest::query()->sole();

        $this->actingAs($this->directorUser);
        $this->post('/attendance/offsite/'.$request->id.'/approve')->assertRedirect();
        $this->from('/attendance/offsite')
            ->post('/attendance/offsite/'.$request->id.'/approve')
            ->assertSessionHasErrors('reason');

        self::assertSame(1, AttendanceRecord::query()->count());
    }

    public function test_employee_cannot_approve_and_sees_only_own_requests(): void
    {
        $this->actingAs($this->workerUser);
        $this->post('/attendance/offsite', [
            'date' => today()->toDateString(),
            'reason' => 'Только моя заявка',
        ])->assertRedirect();

        $request = AttendanceOffsiteRequest::query()->sole();

        $this->actingAs($this->peerUser);
        $this->post('/attendance/offsite/'.$request->id.'/approve')->assertForbidden();
        $this->get('/attendance/offsite')->assertOk()->assertDontSee('Только моя заявка');

        $this->actingAs($this->workerUser);
        $this->get('/attendance/offsite')->assertOk()->assertSee('Только моя заявка');
    }

    public function test_cross_tenant_request_cannot_be_decided(): void
    {
        $otherCompany = Company::create(['name' => 'Other', 'code' => 'other']);
        $otherUser = User::factory()->create();
        $otherEmployee = Employee::create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherUser->id,
            'employee_no' => 'O-1',
            'first_name' => 'Пётр',
            'last_name' => 'Чужой',
            'status' => 'active',
        ]);

        $request = AttendanceOffsiteRequest::create([
            'company_id' => $otherCompany->id,
            'employee_id' => $otherEmployee->id,
            'date' => today()->toDateString(),
            'reason' => 'Чужая заявка',
            'status' => OffsiteRequestStatus::Pending->value,
            'requested_by' => $otherEmployee->id,
        ]);

        $this->actingAs($this->directorUser);
        $this->post('/attendance/offsite/'.$request->id.'/approve')->assertForbidden();
        self::assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_cancel_by_requester_does_not_touch_timesheet(): void
    {
        $this->actingAs($this->workerUser);
        $this->post('/attendance/offsite', [
            'date' => today()->toDateString(),
            'reason' => 'Передумал',
        ])->assertRedirect();

        $request = AttendanceOffsiteRequest::query()->sole();
        $this->post('/attendance/offsite/'.$request->id.'/cancel')->assertRedirect();

        self::assertSame(OffsiteRequestStatus::Cancelled->value, $request->fresh()->status->value);
        self::assertSame(0, AttendanceRecord::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.offsite.cancelled']);
    }
}
