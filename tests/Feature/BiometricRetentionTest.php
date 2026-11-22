<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Biometric\Services\BiometricRetentionService;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** SEC-09: политика удержания очищает payload, но сохраняет журнал событий. */
final class BiometricRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_expired_raw_payloads_are_purged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00'));
        config(['corpms.biometric.raw_payload_retention_days' => 30]);

        $company = Company::create(['name' => 'Retention Co', 'code' => 'retention']);
        $device = BiometricDevice::create([
            'company_id' => $company->id,
            'name' => 'Terminal',
            'serial_number' => 'RET-001',
            'api_token_hash' => str_repeat('a', 64),
        ]);

        $old = BiometricEvent::create([
            'device_id' => $device->id,
            'event_uuid' => 'retention-old',
            'occurred_at' => Carbon::now()->subDays(31),
            'direction' => 'in',
            'raw_payload' => ['card_uid' => 'A-1'],
            'created_at' => Carbon::now()->subDays(31),
        ]);
        $fresh = BiometricEvent::create([
            'device_id' => $device->id,
            'event_uuid' => 'retention-fresh',
            'occurred_at' => Carbon::now(),
            'direction' => 'in',
            'raw_payload' => ['card_uid' => 'A-2'],
        ]);

        $purged = app(BiometricRetentionService::class)->pruneRawPayloads();

        self::assertSame(1, $purged);
        self::assertSame([], $old->fresh()->raw_payload);
        self::assertSame(['card_uid' => 'A-2'], $fresh->fresh()->raw_payload);
        self::assertSame(2, BiometricEvent::query()->count());
    }
}
