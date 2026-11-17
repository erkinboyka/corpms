<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Domains\Biometric\Services\BiometricService;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ТЗ п.67 Security: unauthorized device access.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_token_identifies_device_by_hash(): void
    {
        $this->seed(RbacSeeder::class);

        $company = Company::create(['name' => 'Test Co', 'code' => 'testco2']);

        [, $token] = app(BiometricService::class)->registerDevice([
            'company_id' => $company->id,
            'name' => 'Сканер',
            'serial_number' => 'FT-TOKEN',
            'direction' => 'both',
        ]);

        $device = app(BiometricService::class)->deviceByToken($token);
        $this->assertNotNull($device);
        $this->assertSame('FT-TOKEN', $device->serial_number);

        // повторное использование токена работает
        $this->assertSame($device->id, app(BiometricService::class)->deviceByToken($token)->id);

        // чужой токен не подбирается
        $this->assertNull(app(BiometricService::class)->deviceByToken('bio_unknown' . str_repeat('x', 40)));
    }
}
