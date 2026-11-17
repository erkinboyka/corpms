<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Biometric\Services\BiometricService;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\RfidCard;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Shared\Enums\RfidCardStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RFID (п.72 ТЗ): права biometry.rfid.view/manage, выдача карт, ингресс событий,
 * идемпотентность, отказ (неизвестная/отзыв карты), применение к табелю.
 */
class RfidHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected WorkSchedule $schedule;
    protected User $adminUser;
    protected Employee $adminEmployee;
    protected User $workerUser;
    protected Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'RFID Co', 'code' => 'rfidco']);
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
        $this->adminEmployee = $this->employee('A-1', 'Администратор', 'RF', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Марина', 'Работница', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null, ?int $departmentId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'department_id' => $departmentId,
            'work_schedule_id' => $this->schedule->id,
        ]);
    }

    /** RFID-ридер с токеном. */
    protected function rfidDevice(string $serial, string $direction = 'both', string $deviceType = 'rfid_reader'): array
    {
        [, $token] = app(BiometricService::class)->registerDevice([
            'company_id' => $this->company->id,
            'name' => 'Ридер ' . $serial,
            'serial_number' => $serial,
            'device_type' => $deviceType,
            'direction' => $direction,
            'vendor' => 'generic',
        ]);

        /** @var BiometricDevice $device */
        $device = BiometricDevice::query()->where('serial_number', $serial)->firstOrFail();

        return [$device, $token, hash('sha256', $token)];
    }

    /* -------------------- Web: страницы и права -------------------- */

    public function test_rfid_page_visible_for_admin_and_denied_for_common_employee(): void
    {
        $this->get('/rfid')->assertOk()->assertSee('RFID-карты');

        $this->actingAs($this->workerUser);
        $this->get('/rfid')->assertForbidden();
    }

    public function test_issue_duplicate_card_uid_rejected_and_revoke_works(): void
    {
        $this->post('/rfid', [
            'employee_id' => $this->worker->id,
            'card_uid' => 'CARD-001',
            'label' => 'пропуск 1',
        ])->assertRedirect()->assertSessionHas('status');

        $card = RfidCard::query()->where('card_uid', 'CARD-001')->firstOrFail();
        self::assertSame($this->worker->id, $card->employee_id);
        self::assertSame($this->adminEmployee->id, $card->issued_by);
        self::assertSame(RfidCardStatus::Active->value, $card->status->value);

        $this->assertDatabaseHas('audit_logs', ['action' => 'rfid.card.issued']);

        // дубль UID
        $this->post('/rfid', [
            'employee_id' => $this->adminEmployee->id,
            'card_uid' => 'CARD-001',
        ])->assertRedirect()->assertSessionHasErrors(['card_uid']);
        self::assertSame(1, RfidCard::query()->count());

        // отзыв
        $this->post('/rfid/' . $card->id . '/revoke')->assertRedirect()->assertSessionHas('status');
        $card->refresh();
        self::assertSame(RfidCardStatus::Disabled->value, $card->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rfid.card.revoked']);

        // повторный отзыв — ошибка
        $this->post('/rfid/' . $card->id . '/revoke')->assertSessionHasErrors(['card_uid']);
    }

    /* -------------------- API: ингресс -------------------- */

    public function test_api_unknown_card_rejected_but_logged(): void
    {
        [, $token] = $this->rfidDevice('rdr-1');

        $this->postJson('/api/v1/biometric/device/rfid', [
            'event_uuid' => 'rfid-u1',
            'card_uid' => 'GHOST-CARD',
            'direction' => 'in',
            'timestamp' => '2026-11-03 08:00:00',
        ], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422)
            ->assertJsonPath('status', 'unknown_card');

        $event = BiometricEvent::query()->where('template_type', 'rfid')->sole();
        self::assertNull($event->employee_id);
        self::assertSame('unknown_card', $event->failure_reason);
        self::assertSame('GHOST-CARD', $event->raw_payload['card_uid']);
    }

    public function test_api_processed_event_updates_timesheet_and_idempotent(): void
    {
        [$device, $token] = $this->rfidDevice('rdr-2');

        $this->post('/rfid', [
            'employee_id' => $this->worker->id,
            'card_uid' => 'CARD-OK1',
        ])->assertRedirect();

        $headers = ['Authorization' => 'Bearer ' . $token];

        // вход
        $this->postJson('/api/v1/biometric/device/rfid', [
            'event_uuid' => 'rfid-u2',
            'card_uid' => 'CARD-OK1',
            'direction' => 'in',
            'timestamp' => '2026-11-03 08:50:00',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('attendance.late_minutes', 0)
            ->assertJsonPath('employee.employee_no', 'W-1');

        $record = AttendanceRecord::query()->where('employee_id', $this->worker->id)->sole();
        self::assertSame('08:50', $record->clock_in_at->format('H:i'));
        self::assertSame('biometric', $record->source->value);

        // выход
        $this->postJson('/api/v1/biometric/device/rfid', [
            'event_uuid' => 'rfid-u3',
            'card_uid' => 'CARD-OK1',
            'direction' => 'out',
            'timestamp' => '2026-11-03 18:05:00',
        ], $headers)->assertOk();

        $record->refresh();
        self::assertSame('18:05', $record->clock_out_at->format('H:i'));

        $this->assertDatabaseCount('biometric_events', 2);

        // идемпотентность: повтор того же event_uuid
        $this->postJson('/api/v1/biometric/device/rfid', [
            'event_uuid' => 'rfid-u3',
            'card_uid' => 'CARD-OK1',
            'direction' => 'out',
            'timestamp' => '2026-11-03 18:05:00',
        ], $headers)->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('biometric_events', 2);

        self::assertSame('processed', BiometricEvent::query()->where('event_uuid', 'rfid-u3')->value('processing_status')->value);
    }

    public function test_api_disabled_card_rejected(): void
    {
        [, $token] = $this->rfidDevice('rdr-3');

        RfidCard::create([
            'employee_id' => $this->worker->id,
            'card_uid' => 'CARD-DIS',
            'status' => RfidCardStatus::Disabled,
        ]);

        $this->postJson('/api/v1/biometric/device/rfid', [
            'event_uuid' => 'rfid-u4',
            'card_uid' => 'CARD-DIS',
            'direction' => 'in',
            'timestamp' => '2026-11-03 08:55:00',
        ], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422)
            ->assertJsonPath('status', 'card_disabled');

        self::assertNull(AttendanceRecord::query()->where('employee_id', $this->worker->id)->first());
    }

    public function test_api_direction_mismatch_is_skipped(): void
    {
        [$device, $token] = $this->rfidDevice('rdr-out', 'out');

        RfidCard::create([
            'employee_id' => $this->worker->id,
            'card_uid' => 'CARD-DIR',
        ]);

        $this->postJson('/api/v1/biometric/device/rfid', [
            'event_uuid' => 'rfid-u5',
            'card_uid' => 'CARD-DIR',
            'direction' => 'in',
            'timestamp' => '2026-11-03 08:55:00',
        ], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422)
            ->assertJsonPath('status', 'skipped')
            ->assertJsonPath('event.processing_status', 'skipped');
    }

    public function test_api_bad_token_rejected(): void
    {
        $this->postJson('/api/v1/biometric/device/rfid', ['event_uuid' => 'x'], [
            'Authorization' => 'Bearer bio_wrong',
        ])->assertStatus(401);
    }
}
