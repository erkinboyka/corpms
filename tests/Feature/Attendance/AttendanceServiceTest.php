<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Domains\Attendance\Services\AttendanceService;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Shared\Enums\AttendanceStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Матрица из ТЗ п.67:
 * clock in / clock out / late / early leave / overtime / missing checkout / duplicate.
 */
class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceService $service;
    protected Employee $employee;
    protected WorkSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = new AttendanceService();

        $company = Company::create(['name' => 'Test Co', 'code' => 'testco']);
        $this->schedule = WorkSchedule::create([
            'company_id' => $company->id,
            'name' => '08:00-17:00 (обед 12:00-13:00, grace 10)',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
            'break_minutes' => 60,
            'grace_minutes' => 10,
        ]);

        $user = User::factory()->create();
        $this->employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'TEST-001',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'status' => 'active',
            'work_schedule_id' => $this->schedule->id,
        ]);

        // будний день: 2026-10-29 — четверг
        Carbon::setTestNow(Carbon::parse('2026-10-29 08:00:00'));
    }

    /** п.27: приход 08:03 → ON TIME */
    public function test_clock_in_on_time(): void
    {
        $this->clockIn('2026-10-29 08:03:21');

        $record = $this->todayRecord();

        $this->assertSame(AttendanceStatus::OnTime, $record->status);
        $this->assertEquals('2026-10-29 08:03:21', $record->clock_in_at->format('Y-m-d H:i:s'));
        $this->assertEquals(0, $record->late_minutes);
    }

    /** п.27/71: приход 08:13 → LATE на 13 минут */
    public function test_clock_in_late(): void
    {
        $this->clockIn('2026-10-29 08:13:00');

        $record = $this->todayRecord();

        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertEquals(13, $record->late_minutes);
    }

    /** grace 10 минут не считается опозданием: 08:10 → ON TIME */
    public function test_clock_in_at_grace_boundary_still_on_time(): void
    {
        $this->clockIn('2026-10-29 08:10:00');

        $this->assertSame(AttendanceStatus::OnTime, $this->todayRecord()->status);
    }

    /** п.28: выход 17:04 → 08:01 отработано, overtime 4 мин */
    public function test_clock_out_counts_worked_and_overtime(): void
    {
        $this->clockIn('2026-10-29 08:00:00');
        $this->clockOut('2026-10-29 17:04:12');

        $record = $this->todayRecord();

        // 08:00–17:04 = 9h04m span; −60 обеда = 8h04m фактически; overtime 4 мин
        $this->assertEquals(8 * 60 + 4, $record->worked_minutes);
        $this->assertEquals(4, $record->overtime_minutes);
        $this->assertEquals(60, $record->break_minutes);
        $this->assertSame(AttendanceStatus::Overtime, $record->status);
        $this->assertEquals(60, $record->break_minutes);
        $this->assertSame(AttendanceStatus::Overtime, $record->status);
    }

    /** ранний уход: выход в 16:30 → early_leave 30 мин */
    public function test_clock_out_early_leave(): void
    {
        $this->clockIn('2026-10-29 08:00:00');
        $this->clockOut('2026-10-29 16:30:00');

        $record = $this->todayRecord();

        $this->assertEquals(30, $record->early_leave_minutes);
        $this->assertSame(AttendanceStatus::EarlyLeave, $record->status);
    }

    /** отработанное = span − обед */
    public function test_break_subtracted_from_worked(): void
    {
        $this->clockIn('2026-10-29 08:00:00');
        $this->clockOut('2026-10-29 17:00:00');

        $record = $this->todayRecord();

        $this->assertEquals(60, $record->break_minutes);
        $this->assertEquals(8 * 60, $record->worked_minutes); // 08:00–17:00 = 9ч − 1ч обед
        $this->assertSame(AttendanceStatus::OnTime, $record->status);
    }

    /** выходной/праздник: не опоздание */
    public function test_weekend_status_is_weekend(): void
    {
        $this->clockIn('2026-10-31 09:00:00'); // суббота

        $record = $this->todayRecord('2026-10-31');
        $this->assertSame(AttendanceStatus::Weekend, $record->status);
    }

    /** дубликат входа не перезатирает первое приход */
    public function test_second_clock_in_ignored(): void
    {
        $this->clockIn('2026-10-29 08:03:21');
        $this->clockIn('2026-10-29 09:45:00'); // повторный проход через камеру

        $record = $this->todayRecord();
        $this->assertEquals('08:03:21', $record->clock_in_at->format('H:i:s'));
    }

    /** п.29: shедуляр missing checkout → аномалия */
    public function test_missing_checkout_anomaly_detected(): void
    {
        $this->clockIn('2026-10-29 08:03:21');
        $this->clockIn('2026-10-30 08:05:00'); // следующий день — тоже без выхода

        $count = $this->service->detectMissingCheckout(Carbon::parse('2026-10-29'));
        $count2 = $this->service->detectMissingCheckout(Carbon::parse('2026-10-30'));

        $this->assertEquals(1, $count);
        $this->assertEquals(1, $count2);
        $this->assertTrue(\App\Models\AttendanceAnomaly::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-10-29')->where('type', 'missing_checkout')->exists());
        $this->assertTrue(\App\Models\AttendanceAnomaly::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-10-30')->where('type', 'missing_checkout')->exists());
    }

    /** повторный пересчёт — детерминирован (без нарастаний) */
    public function test_recompute_is_idempotent(): void
    {
        $this->clockIn('2026-10-29 08:15:00');
        $this->clockOut('2026-10-29 18:10:00');

        $record = $this->todayRecord();

        $first = [$record->worked_minutes, $record->overtime_minutes, $record->late_minutes];
        $this->service->recompute($record);
        $this->service->recompute($record->fresh());

        $record = $record->fresh();
        $this->assertEquals($first[0], $record->worked_minutes);
        $this->assertEquals($first[1], $record->overtime_minutes);
        $this->assertEquals($first[2], $record->late_minutes);
    }

    /* ---------------- helpers ---------------- */

    public function clockIn(string $time): void
    {
        $this->makeEvent('in', $time);
    }

    public function clockOut(string $time): void
    {
        $this->makeEvent('out', $time);
    }

    public function makeEvent(string $direction, string $time): BiometricEvent
    {
        $device = $this->deviceFor($direction);

        $event = BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $this->employee->id,
            'event_uuid' => 'uuid-' . $direction . '-' . $time,
            'occurred_at' => $time,
            'direction' => $direction,
            'raw_payload' => [],
            'processing_status' => 'pending',
        ]);

        $record = $this->service->applyEvent($this->employee, $event);

        $this->assertNotNull($record, 'applyEvent не вернултабельной записи');

        return $event;
    }

    public function deviceFor(string $direction): BiometricDevice
    {
        return BiometricDevice::firstOrCreate([
            'company_id' => 1,
            'name' => 'Main ' . $direction,
            'serial_number' => 'SN-' . $direction . '-001',
            'direction' => $direction,
            'api_token_hash' => 'x' . $direction,
            'status' => 'online',
        ]);
    }

    private function todayRecord(string $date = '2026-10-29')
    {
        return \App\Models\AttendanceRecord::query()->where('employee_id', $this->employee->id)
            ->whereDate('date', $date)->first();
    }
}
