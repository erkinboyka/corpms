<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Biometric\Services\BiometricService;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricEvent;
use App\Models\BiometricProfile;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\EnumLabels;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-контур биометрии (п.24–27, 67, 73 ТЗ): устройства, профили, enrollment.
 */
class BiometricsHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Bio Co', 'code' => 'bioco']);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Биометров', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Пётр', 'Работников', $this->workerUser->id);

        $schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
            'grace_minutes' => 10,
        ]);
        $this->worker->update(['work_schedule_id' => $schedule->id]);

        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    protected function device(array $attributes = []): BiometricDevice
    {
        return BiometricDevice::create($attributes + [
            'company_id' => $this->company->id,
            'name' => 'Турникет lobby',
            'serial_number' => 'SN-'.uniqid(),
            'device_type' => 'face_terminal',
            'vendor' => 'generic',
            'direction' => 'both',
            'status' => 'online',
            'last_seen_at' => now(),
            'api_token_hash' => hash('sha256', 'x'),
        ]);
    }

    /* -------------------- просмотр -------------------- */

    public function test_index_renders_for_admin(): void
    {
        $device = $this->device(['last_seen_at' => now()->subHours(2)]);

        $this->get('/biometrics')
            ->assertOk()
            ->assertSee('Матрица устройств')
            ->assertSee('Турникет lobby')
            // устройство со старым last_seen считается offline
            ->assertSee('offline');

        $device->refresh();
        self::assertSame('online', $device->status->value);
    }

    public function test_index_denied_without_permission(): void
    {
        $this->actingAs($this->workerUser);
        $this->get('/biometrics')->assertForbidden();
    }

    /* -------------------- устройства -------------------- */

    public function test_register_device_shows_token_once_and_audits(): void
    {
        $response = $this->post('/biometrics/devices', [
            'name' => 'Терминал вход со стороны улицы',
            'serial_number' => 'SN-NEW-1',
            'device_type' => 'face_terminal',
            'vendor' => 'generic',
            'direction' => 'in',
            'location' => '1 этаж',
        ]);

        $response->assertRedirect();

        $flash = session('status') ?? '';
        self::assertStringContainsString('bio_', $flash, 'токен должен быть показан один раз');

        $device = BiometricDevice::query()->where('serial_number', 'SN-NEW-1')->firstOrFail();
        self::assertSame('Терминал вход со стороны улицы', $device->name);
        self::assertSame($this->company->id, $device->company_id);
        // токен в БД — хэш, а не открытый вид
        self::assertSame(hash('sha256', trim(preg_replace('/^.*bio_/s', 'bio_', $flash))), $device->api_token_hash);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.registered',
            'object_id' => $device->id,
        ]);
    }

    /** DB-03/DB-11: БД поддерживает rfid_reader, UI обязан принимать тот же тип. */
    public function test_register_device_accepts_rfid_reader_type(): void
    {
        $this->post('/biometrics/devices', [
            'name' => 'RFID-ридер проходной',
            'serial_number' => 'SN-RFID-UI-1',
            'device_type' => 'rfid_reader',
            'vendor' => 'generic',
            'direction' => 'both',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $device = BiometricDevice::query()->where('serial_number', 'SN-RFID-UI-1')->firstOrFail();
        self::assertSame('rfid_reader', $device->device_type);
        self::assertSame('RFID-ридер', EnumLabels::label('device_type', 'rfid_reader'));
    }

    public function test_device_validation_rejects_duplicate_serial(): void
    {
        $this->device(['serial_number' => 'SN-DUP']);

        $this->from('/biometrics')
            ->post('/biometrics/devices', [
                'name' => 'Dup',
                'serial_number' => 'SN-DUP',
                'device_type' => 'face_terminal',
                'vendor' => 'generic',
                'direction' => 'both',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['serial_number']);

        self::assertSame(1, BiometricDevice::query()->where('serial_number', 'SN-DUP')->count());
    }

    public function test_device_update_and_delete(): void
    {
        $device = $this->device();

        $this->post('/biometrics/devices/'.$device->id, [
            'status' => 'disabled',
            'location' => '2 этаж',
        ])->assertRedirect()->assertSessionHas('status', 'Устройство обновлено');

        $device->refresh();
        self::assertSame('disabled', $device->status->value);
        self::assertSame('2 этаж', $device->location);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.updated']);

        $this->post('/biometrics/devices/'.$device->id.'/delete')->assertRedirect();
        self::assertSoftDeletedOrGone($device);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.deleted']);
    }

    protected static function assertSoftDeletedOrGone(BiometricDevice $device): void
    {
        self::assertTrue(
            $device->fresh() === null || $device->fresh()->trashed(),
            'устройство должно быть удалено',
        );
    }

    /* -------------------- профили (enroll) -------------------- */

    public function test_enroll_creates_profile_and_enrollment_record(): void
    {
        $device = $this->device();

        $this->post('/biometrics/profiles', [
            'employee_id' => $this->worker->id,
            'device_id' => $device->id,
            'vendor' => 'generic',
            'template_ref' => 'tpl://device/42',
            'template_hash' => hash('sha256', 'template-body'),
        ])->assertRedirect()->assertSessionHas('status');

        $profile = BiometricProfile::query()
            ->where('employee_id', $this->worker->id)
            ->where('vendor', 'generic')
            ->firstOrFail();

        self::assertSame('enrolled', $profile->status->value);
        self::assertSame(hash('sha256', 'template-body'), $profile->template_hash);
        self::assertSame($this->adminEmployee->id, $profile->enrolled_by);
        self::assertNotNull($profile->enrolled_at);

        self::assertTrue(
            BiometricEnrollment::query()
                ->where('device_id', $device->id)
                ->where('employee_id', $this->worker->id)
                ->exists(),
            'акт регистрации должен попасть в журнал устройства',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'biometry.enrolled',
            'object_id' => $profile->id,
        ]);
    }

    public function test_enroll_requires_manage_permission(): void
    {
        $this->actingAs($this->workerUser);
        $device = $this->device();

        $this->post('/biometrics/profiles', [
            'employee_id' => $this->worker->id,
            'device_id' => $device->id,
            'vendor' => 'generic',
            'template_hash' => 'abc',
        ])->assertForbidden();

        self::assertSame(0, BiometricProfile::query()->count());
    }

    public function test_disable_and_delete_profile(): void
    {
        $device = $this->device();

        app(BiometricService::class)
            ->enrollProfile($this->worker, 'generic', null, hash('sha256', 't'));

        $profile = BiometricProfile::query()->where('employee_id', $this->worker->id)->firstOrFail();

        $this->post('/biometrics/profiles/'.$profile->id.'/disable')->assertRedirect();
        $profile->refresh();

        $this->assertDatabaseHas('biometric_profiles', [
            'id' => $profile->id,
            'status' => 'disabled',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'biometry.disabled']);

        $this->post('/biometrics/profiles/'.$profile->id.'/delete')->assertRedirect();
        self::assertNull($profile->fresh(), 'профиль должен быть удалён');
        $this->assertDatabaseHas('audit_logs', ['action' => 'biometry.deleted']);
    }

    /* -------------------- идемпотентность Device API (п.24) -------------------- */

    public function test_device_api_token_works_and_repeats_uuid_is_idempotent(): void
    {
        [, $token] = app(BiometricService::class)
            ->registerDevice([
                'company_id' => $this->company->id,
                'name' => 'API Terminal',
                'serial_number' => 'SN-API-1',
                'device_type' => 'face_terminal',
                'vendor' => 'generic',
                'direction' => 'both',
            ]);

        $payload = [
            'event_uuid' => 'evt-idem-1',
            'employee_no' => 'W-1',
            'direction' => 'in',
            'timestamp' => now()->toDateTimeString(),
            'liveness' => true,
        ];

        $first = $this->postJson('/api/v1/biometric/device/events', $payload, [
            'Authorization' => 'Bearer '.$token,
        ]);
        $first->assertStatus(200);

        $second = $this->postJson('/api/v1/biometric/device/events', $payload, [
            'Authorization' => 'Bearer '.$token,
        ]);
        $second->assertStatus(200);

        // событие одно — идемпотентность по event_uuid
        self::assertSame(1, BiometricEvent::query()->where('event_uuid', 'evt-idem-1')->count());
    }

    public function test_liveness_failure_is_rejected_with_422(): void
    {
        [, $token] = app(BiometricService::class)
            ->registerDevice([
                'company_id' => $this->company->id,
                'name' => 'Liveness Terminal',
                'serial_number' => 'SN-LIVE-1',
                'device_type' => 'face_terminal',
                'vendor' => 'generic',
                'direction' => 'both',
            ]);

        $this->postJson('/api/v1/biometric/device/events', [
            'event_uuid' => 'evt-live-1',
            'employee_no' => 'W-1',
            'direction' => 'in',
            'timestamp' => now()->toDateTimeString(),
            'liveness' => false,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'liveness_failed');
    }
}
