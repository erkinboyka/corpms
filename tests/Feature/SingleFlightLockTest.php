<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SingleFlightLock;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** P1 BE-06/PERF-06: атомарный single-flight lock. */
class SingleFlightLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_only_one_caller_holds_the_lease(): void
    {
        $first = SingleFlightLock::acquire('accounting.sync:1');
        $second = SingleFlightLock::acquire('accounting.sync:1');

        $this->assertIsString($first);
        $this->assertNull($second);

        SingleFlightLock::release('accounting.sync:1', $first);

        $this->assertIsString(SingleFlightLock::acquire('accounting.sync:1'));
    }

    public function test_stale_token_cannot_release_someone_elses_lease(): void
    {
        $first = SingleFlightLock::acquire('bi.rebuild:1');
        $this->assertIsString($first);

        SingleFlightLock::release('bi.rebuild:1', 'stale-token');

        $this->assertNull(SingleFlightLock::acquire('bi.rebuild:1'));
    }
}
