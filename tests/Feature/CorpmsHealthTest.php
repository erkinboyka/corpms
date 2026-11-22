<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\CorpmsHealth;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Инфраструктурный health-check: корректный отчёт и падение на проблемах
 * очереди (failed jobs / переполнение backlog).
 */
class CorpmsHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml ставит sync-очередь; health проверяет настроенный backend,
        // поэтому для теста переключаемся на database (как в .env приложения).
        config(['queue.default' => 'database']);
    }

    /**
     * @return array{ok: bool, checks: array<int, array{name: string, ok: bool, details: string}>}
     */
    protected function runHealth(array $options = ['--json' => true]): array
    {
        Artisan::call('corpms:health', $options);

        $report = json_decode(Artisan::output(), true);

        self::assertIsArray($report, 'Команда должна вернуть JSON: '.Artisan::output());

        return $report;
    }

    public function test_health_is_ok_on_clean_environment(): void
    {
        $exit = Artisan::call('corpms:health', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        self::assertSame(0, $exit);
        self::assertTrue($report['ok']);
        self::assertSame(
            ['database', 'migrations', 'queue', 'storage'],
            array_column($report['checks'], 'name'),
        );

        $migrations = collect($report['checks'])->firstWhere('name', 'migrations');
        self::assertTrue($migrations['ok']);
        self::assertSame('все применены', $migrations['details']);
    }

    public function test_health_writes_scheduler_heartbeat(): void
    {
        config(['cache.default' => 'array']);
        Cache::forget('ops.health.last_run');

        $this->runHealth();

        $this->assertNotNull(Cache::get('ops.health.last_run'));
    }

    public function test_failed_jobs_make_health_fail(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Тестовое падение',
            'failed_at' => now(),
        ]);

        $report = $this->runHealth();
        $queue = collect($report['checks'])->firstWhere('name', 'queue');

        self::assertFalse($report['ok']);
        self::assertFalse($queue['ok']);
        self::assertStringContainsString('failed 1', $queue['details']);
    }

    public function test_queue_backlog_threshold_is_enforced(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $report = $this->runHealth(['--json' => true, '--max-jobs' => '0']);
        $queue = collect($report['checks'])->firstWhere('name', 'queue');

        self::assertFalse($report['ok']);
        self::assertFalse($queue['ok']);
        self::assertStringContainsString('порог 0', $queue['details']);
    }

    public function test_database_connection_failure_is_reported(): void
    {
        $original = config('database.default');
        config(['database.default' => 'no_such_connection']);

        try {
            $report = $this->runHealth();
            $database = collect($report['checks'])->firstWhere('name', 'database');

            self::assertFalse($report['ok']);
            self::assertFalse($database['ok']);
            self::assertStringContainsString('no_such_connection', $database['details']);
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_pending_migrations_make_health_fail(): void
    {
        $repository = $this->mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('getRan')->andReturn([]);

        $migrator = $this->mock(Migrator::class);
        $migrator->shouldReceive('getMigrationFiles')->andReturn([
            '2099_01_01_000000_pending.php' => 'pending',
        ]);
        $migrator->shouldReceive('getRepository')->andReturn($repository);

        $report = $this->runHealth();
        $migrations = collect($report['checks'])->firstWhere('name', 'migrations');

        self::assertFalse($report['ok']);
        self::assertFalse($migrations['ok']);
        self::assertStringContainsString('2099_01_01_000000_pending.php', $migrations['details']);
    }

    public function test_missing_storage_directories_make_health_fail(): void
    {
        $original = app()->storagePath();
        app()->useStoragePath(sys_get_temp_dir().'/corpms_missing_'.Str::uuid());
        try {
            $command = app(CorpmsHealth::class);
            $check = (new \ReflectionMethod($command, 'checkStorage'))->invoke($command);
            self::assertFalse($check['ok']);
            foreach (['storage', 'logs', 'app'] as $directory) {
                self::assertStringContainsString($directory, $check['details']);
            }
        } finally {
            app()->useStoragePath($original);
        }
    }
}
