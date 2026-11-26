<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Domains\Attendance\Services\AttendanceCalculationService;
use App\Domains\Attendance\Services\AttendanceService;
use App\Models\AttendanceOffsiteRequest;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Shared\Enums\AttendanceSource;
use App\Shared\Enums\AttendanceStatus;
use App\Shared\Enums\AttendanceWorkType;
use App\Shared\Enums\LeaveStatus;
use App\Shared\Enums\LeaveType;
use App\Shared\Enums\OffsiteRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** ТЗ п.23: приоритет отпуск → больничный → командировка → APPROVED offsite → офис → выходной. */
class AttendanceCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected const DAY = '2026-10-29'; // четверг

    protected Company $company;

    protected Employee $employee;

    protected WorkSchedule $schedule;

    protected AttendanceCalculationService $calculation;

    protected AttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00'));

        $this->company = Company::create(['name' => 'Calc Co', 'code' => 'calcco']);
        $this->schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'type' => 'standard',
            'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
        ]);
        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'C-1',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'status' => 'active',
            'work_schedule_id' => $this->schedule->id,
        ]);

        $this->calculation = new AttendanceCalculationService;
        $this->attendance = new AttendanceService($this->calculation);
    }

    protected function leave(string $type, string $status = LeaveStatus::Approved->value): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'type' => $type,
            'from_date' => self::DAY,
            'to_date' => self::DAY,
            'status' => $status,
        ]);
    }

    protected function offsite(string $status): AttendanceOffsiteRequest
    {
        return AttendanceOffsiteRequest::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => self::DAY,
            'reason' => 'Удалённая работа',
            'status' => $status,
            'requested_by' => $this->employee->id,
        ]);
    }

    protected function biometric(string $time): AttendanceRecord
    {
        $device = BiometricDevice::firstOrCreate([
            'company_id' => $this->company->id,
            'name' => 'Main in',
            'serial_number' => 'SN-CALC-IN',
            'direction' => 'in',
            'api_token_hash' => 'calc-in',
            'status' => 'online',
        ]);
        $event = BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $this->employee->id,
            'event_uuid' => 'calc-'.$time,
            'occurred_at' => $time,
            'direction' => 'in',
            'raw_payload' => [],
            'processing_status' => 'pending',
        ]);

        return $this->attendance->applyEvent($this->employee, $event);
    }

    public function test_vacation_beats_every_other_source(): void
    {
        $this->leave(LeaveType::Sick->value);
        $this->leave(LeaveType::BusinessLeave->value);
        $this->leave(LeaveType::Vacation->value);
        $this->offsite(OffsiteRequestStatus::Approved->value);

        $record = $this->biometric(self::DAY.' 09:30:00');

        self::assertSame(AttendanceStatus::Present, $record->status);
        self::assertSame(AttendanceWorkType::Leave->value, $record->work_type);
        self::assertSame(AttendanceSource::System, $record->source);
        self::assertSame(0, (int) $record->late_minutes);
    }

    public function test_sick_beats_business_trip_and_approved_offsite(): void
    {
        $this->leave(LeaveType::Sick->value);
        $this->leave(LeaveType::BusinessLeave->value);
        $this->offsite(OffsiteRequestStatus::Approved->value);

        $resolved = $this->calculation->resolve($this->employee, Carbon::parse(self::DAY));

        self::assertSame(AttendanceWorkType::Sick, $resolved['work_type']);
        self::assertSame(AttendanceStatus::Present, $resolved['status']);
    }

    public function test_business_trip_beats_approved_offsite(): void
    {
        $this->leave(LeaveType::BusinessLeave->value);
        $this->offsite(OffsiteRequestStatus::Approved->value);

        $resolved = $this->calculation->resolve($this->employee, Carbon::parse(self::DAY));

        self::assertSame(AttendanceWorkType::BusinessTrip, $resolved['work_type']);
    }

    public function test_pending_offsite_is_not_presence_and_does_not_override_office(): void
    {
        $this->offsite(OffsiteRequestStatus::Pending->value);

        self::assertNull($this->calculation->resolve($this->employee, Carbon::parse(self::DAY)));

        $record = $this->biometric(self::DAY.' 08:05:00');
        self::assertSame(AttendanceWorkType::Office->value, $record->work_type);
        self::assertSame(AttendanceStatus::OnTime, $record->status);
        self::assertSame(0, (int) $record->late_minutes);
    }

    public function test_approved_offsite_beats_office_biometrics_without_lateness(): void
    {
        $this->offsite(OffsiteRequestStatus::Approved->value);

        self::assertSame(1, AttendanceOffsiteRequest::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('date', self::DAY)
            ->where('status', OffsiteRequestStatus::Approved->value)
            ->count());

        self::assertNotNull(
            $this->calculation->resolve($this->employee, Carbon::parse(self::DAY)),
            'APPROVED offsite must override office layer',
        );

        $record = $this->biometric(self::DAY.' 09:30:00');

        self::assertSame(AttendanceStatus::Present, $record->status);
        self::assertSame(AttendanceWorkType::Offsite->value, $record->work_type);
        self::assertSame(AttendanceSource::OffsiteRequest, $record->source);
        self::assertSame(0, (int) $record->late_minutes);
    }

    public function test_biometric_event_does_not_overwrite_offsite_request_source(): void
    {
        $this->offsite(OffsiteRequestStatus::Approved->value);
        AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'date' => Carbon::parse(self::DAY),
            'status' => AttendanceStatus::Present,
            'work_type' => AttendanceWorkType::Offsite->value,
            'source' => AttendanceSource::OffsiteRequest,
        ]);

        $record = $this->biometric(self::DAY.' 09:30:00');

        self::assertSame(AttendanceSource::OffsiteRequest, $record->source);
        self::assertSame(AttendanceWorkType::Offsite->value, $record->work_type);
        self::assertSame(AttendanceStatus::Present, $record->status);
    }

    public function test_day_off_is_last_priority(): void
    {
        $this->offsite(OffsiteRequestStatus::Rejected->value);

        $resolved = $this->calculation->resolve($this->employee, Carbon::parse('2026-11-01')); // воскресенье

        self::assertSame(AttendanceWorkType::DayOff, $resolved['work_type']);
        self::assertSame(AttendanceStatus::Weekend, $resolved['status']);
    }

    public function test_custom_working_days_make_saturday_a_workday(): void
    {
        $this->schedule->update(['work_days' => [1, 2, 3, 4, 6]]);

        // Суббота теперь рабочая → приоритетного источника нет, офис считает face.
        self::assertNull($this->calculation->resolve($this->employee, Carbon::parse('2026-10-31')));

        $record = $this->biometric('2026-10-31 08:05:00');
        self::assertSame(AttendanceStatus::OnTime, $record->status);
        self::assertSame(AttendanceWorkType::Office->value, $record->work_type);

        // Воскресенье по-прежнему вне графика.
        $resolved = $this->calculation->resolve($this->employee, Carbon::parse('2026-11-01'));
        self::assertSame(AttendanceWorkType::DayOff, $resolved['work_type']);
    }

    public function test_operating_holiday_overrides_weekend(): void
    {
        Holiday::create([
            'company_id' => $this->company->id,
            'name' => 'Рабочая суббота',
            'date' => '2026-10-31',
            'kind' => 'operating',
            'is_day_off' => false,
        ]);

        self::assertNull($this->calculation->resolve($this->employee, Carbon::parse('2026-10-31')));
    }

    public function test_day_off_holiday_still_wins_on_a_working_day(): void
    {
        Holiday::create([
            'company_id' => $this->company->id,
            'name' => 'Праздник',
            'date' => self::DAY,
            'kind' => 'public',
            'is_day_off' => true,
        ]);

        $resolved = $this->calculation->resolve($this->employee, Carbon::parse(self::DAY));
        self::assertSame(AttendanceWorkType::DayOff, $resolved['work_type']);
    }

    public function test_pending_leave_does_not_override_face_results(): void
    {
        $this->leave(LeaveType::Vacation->value, LeaveStatus::Pending->value);

        $record = $this->biometric(self::DAY.' 09:30:00');

        self::assertSame(AttendanceStatus::Late, $record->status);
        self::assertSame(AttendanceWorkType::Office->value, $record->work_type);
        self::assertSame(90, (int) $record->late_minutes);
    }
}
