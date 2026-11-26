<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\QueueHealthCommand;
use App\Domains\Queue\Jobs\VerifyWorkerPool;
use App\Support\Queue\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Надзор за пулами воркеров: heartbeat, canary, зависшие задания. */
final class QueueOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'cache.default' => 'array',
            'worker.pools.default.queue' => 'default',
            'worker.pools.bi.queue' => 'bi',
            'worker.heartbeat_ttl_minutes' => 10,
            'worker.stale_minutes' => 15,
        ]);

        Cache::forget('ops.worker.heartbeat:default');
        Cache::forget('ops.worker.heartbeat:bi');
    }

    /**
     * @return array{ok: bool, pools: array<int, array{pool: string, queue: string, ok: bool}>}
     */
    private function health(array $options = ['--json' => true]): array
    {
        Artisan::call('queue:health', $options);

        $report = json_decode(Artisan::output(), true);
        self::assertIsArray($report, Artisan::output());

        return $report;
    }

    public function test_health_is_ok_when_both_pools_have_fresh_heartbeat(): void
    {
        $heartbeat = app(WorkerHeartbeat::class);
        $heartbeat->beat('default');
        $heartbeat->beat('bi');

        $report = $this->health();

        self::assertTrue($report['ok']);
        self::assertSame(['default', 'bi'], array_column($report['pools'], 'pool'));
        self::assertTrue($report['pools'][0]['ok']);
        self::assertTrue($report['pools'][1]['ok']);
    }

    public function test_missing_heartbeat_makes_pool_unhealthy(): void
    {
        app(WorkerHeartbeat::class)->beat('default');

        $report = $this->health();

        self::assertFalse($report['ok']);
        self::assertFalse($report['pools'][1]['ok']);
        self::assertNull($report['pools'][1]['heartbeat']['at']);
    }

    public function test_stale_reserved_job_is_reported_and_can_be_released(): void
    {
        app(WorkerHeartbeat::class)->beat('default');
        app(WorkerHeartbeat::class)->beat('bi');

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->subMinutes(30)->timestamp,
            'available_at' => now()->subMinutes(30)->timestamp,
            'created_at' => now()->subMinutes(30)->timestamp,
        ]);

        $report = $this->health();
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['pools'][0]['stale']);

        Artisan::call('queue:watchdog', [
            '--release' => true,
            '--stale-minutes' => 15,
        ]);

        $job = DB::table('jobs')->firstOrFail();
        self::assertNull($job->reserved_at);
        self::assertSame(2, (int) $job->attempts);
    }

    public function test_canary_job_targets_configured_pool_and_writes_heartbeat(): void
    {
        $job = new VerifyWorkerPool('bi');

        self::assertSame('bi', $job->queue);

        $job->handle(app(WorkerHeartbeat::class));

        $status = app(WorkerHeartbeat::class)->status('bi');
        self::assertTrue($status['fresh']);
        self::assertNotNull($status['at']);
    }

    public function test_selfcheck_dispatches_canary_to_both_queues(): void
    {
        Artisan::call('worker:selfcheck');

        self::assertEqualsCanonicalizing(
            ['default', 'bi'],
            DB::table('jobs')->orderBy('id')->pluck('queue')->all(),
        );
    }

    public function test_queue_health_command_uses_configured_stale_threshold(): void
    {
        $command = app(QueueHealthCommand::class);
        $property = new \ReflectionProperty(QueueHealthCommand::class, 'signature');
        $property->setAccessible(true);

        self::assertStringContainsString('--stale-minutes=', (string) $property->getValue($command));
    }
}
