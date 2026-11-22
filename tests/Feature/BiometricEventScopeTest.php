<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Biometric\Services\BiometricService;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** BE-S1: event_uuid уникален в рамках компании, идемпотентность не cross-tenant. */
final class BiometricEventScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_same_event_uuid_is_allowed_in_another_company(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 08:00:00'));
        $this->seed(RbacSeeder::class);

        [$employeeA, $tokenA] = $this->createTenant('scope-a', 'SCOPE-A', 'DEV-A');
        [$employeeB, $tokenB] = $this->createTenant('scope-b', 'SCOPE-B', 'DEV-B');

        $this->postJson(
            '/api/v1/biometric/device/events',
            $this->payload($employeeA->employee_no),
            ['Authorization' => 'Bearer '.$tokenA],
        )->assertOk()->assertJsonPath('status', 'processed');

        $this->postJson(
            '/api/v1/biometric/device/events',
            $this->payload($employeeB->employee_no),
            ['Authorization' => 'Bearer '.$tokenB],
        )->assertOk()->assertJsonPath('status', 'processed');

        self::assertSame(2, BiometricEvent::query()->where('event_uuid', 'shared-uuid')->count());

        $this->postJson(
            '/api/v1/biometric/device/events',
            $this->payload($employeeA->employee_no),
            ['Authorization' => 'Bearer '.$tokenA],
        )->assertOk()->assertJsonPath('status', 'duplicate');

        self::assertSame(2, BiometricEvent::query()->where('event_uuid', 'shared-uuid')->count());
    }

    /** @return array{Employee, string} */
    private function createTenant(string $code, string $serial, string $employeeNo): array
    {
        $company = Company::create(['name' => $code, 'code' => $code]);
        $schedule = WorkSchedule::create([
            'company_id' => $company->id,
            'name' => 'std-'.$code,
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
            'grace_minutes' => 10,
        ]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $employeeNo,
            'first_name' => 'Тест',
            'last_name' => $code,
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
        ]);
        [, $token] = app(BiometricService::class)->registerDevice([
            'company_id' => $company->id,
            'name' => 'Terminal '.$code,
            'serial_number' => $serial,
            'direction' => 'in',
            'vendor' => 'generic',
        ]);

        return [$employee, $token];
    }

    /** @return array<string, mixed> */
    private function payload(string $employeeNo): array
    {
        return [
            'event_uuid' => 'shared-uuid',
            'employee_no' => $employeeNo,
            'direction' => 'in',
            'timestamp' => '2026-11-22 08:03:21',
            'confidence' => 0.99,
            'liveness' => true,
        ];
    }
}
