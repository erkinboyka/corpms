<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Domains\Biometric\Services\BiometricService;
use App\Models\AttendanceRecord;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * HTTP-контур: device token auth + идемпотентность + расчёты через API (п.67 ТЗ).
 */
class DeviceApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $company = Company::create(['name' => 'Test Co', 'code' => 'testco']);
        WorkSchedule::create([
            'company_id' => $company->id,
            'name' => 'std',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
            'grace_minutes' => 10,
        ]);

        $this->employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'DEV-100',
            'first_name' => 'Сергей',
            'last_name' => 'Сергеев',
            'status' => 'active',
            'work_schedule_id' => WorkSchedule::first()->id,
        ]);

        // камера на вход, направление IN
        [, $this->token] = app(BiometricService::class)->registerDevice([
            'company_id' => $company->id,
            'name' => 'Main Entrance',
            'location' => 'Главный вход',
            'serial_number' => 'FT-001',
            'direction' => 'in',
            'vendor' => 'generic',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-29 08:00:00'));
    }

    public function test_invalid_token_rejected(): void
    {
        $this->postJson('/api/v1/biometric/device/events', self::payload(), [
            'Authorization' => 'Bearer bio_wrongtoken',
        ])->assertStatus(401);
    }

    public function test_valid_event_clocks_in(): void
    {
        $response = $this->postJson('/api/v1/biometric/device/events', self::payload('08:03:21'), [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('attendance.status', 'on_time')
            ->assertJsonPath('employee.employee_no', 'DEV-100');
    }

    public function test_duplicate_event_uuid_processing_once(): void
    {
        $this->postJson('/api/v1/biometric/device/events', self::payload('08:03:21'), ['Authorization' => 'Bearer '.$this->token]);
        Carbon::setTestNow(Carbon::parse('2026-10-29 09:00:00'));

        // повтор той же ПОСЫЛКИ (офлайн-синк мог задублировать)
        $r2 = $this->postJson('/api/v1/biometric/device/events', self::payload('08:03:21'), ['Authorization' => 'Bearer '.$this->token]);
        $r2->assertOk()->assertJsonPath('status', 'duplicate');

        $records = AttendanceRecord::query()->get();
        $this->assertCount(1, $records);
        $this->assertEquals('08:03:21', $records->first()->clock_in_at->format('H:i:s'));
    }

    public function test_unknown_employee_returns_error_but_logs_event(): void
    {
        $this->postJson('/api/v1/biometric/device/events', array_merge(self::payload('08:03:21'), [
            'employee_no' => 'NOPE',
        ]), ['Authorization' => 'Bearer '.$this->token])->assertStatus(422)->assertJsonMissing(['status' => 'processed']);

        $this->assertDatabaseHas('biometric_events', [
            'event_uuid' => 'e1',
            'processing_status' => 'failed',
        ]);
    }

    /** SEC-09: терминал не может записать в журнал произвольные поля/PДн. */
    public function test_only_allowlisted_payload_fields_are_stored(): void
    {
        $this->postJson('/api/v1/biometric/device/events', array_merge(self::payload('08:03:21'), [
            'face_template' => 'raw-template',
            'debug' => ['trace' => 'token'],
        ]), ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $raw = BiometricEvent::query()->where('event_uuid', 'e1')->value('raw_payload');
        self::assertIsArray($raw);
        self::assertSame('DEV-100', $raw['employee_no']);
        self::assertArrayNotHasKey('face_template', $raw);
        self::assertArrayNotHasKey('debug', $raw);
    }

    public function test_direction_mismatch_rejected(): void
    {
        // камера входа прислала OUT — направление не совпадает
        $response = $this->postJson('/api/v1/biometric/device/events', array_merge(self::payload('08:03:21'), [
            'direction' => 'out',
        ]), ['Authorization' => 'Bearer '.$this->token]);

        $response->assertStatus(422)->assertJsonPath('status', 'skipped');
        $this->assertDatabaseMissing('attendance_records', []);
    }

    public function test_late_computed_via_api(): void
    {
        $this->postJson('/api/v1/biometric/device/events', self::payload('08:25:00'), ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'late')
            ->assertJsonPath('attendance.late_minutes', 25);
    }

    private static function payload(string $time = '08:03:21'): array
    {
        return [
            'event_uuid' => 'e1',
            'employee_no' => 'DEV-100',
            'direction' => 'in',
            'timestamp' => '2026-10-29 '.$time,
            'confidence' => 0.99,
            'liveness' => true,
        ];
    }
}
