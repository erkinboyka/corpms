<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Инфраструктурный health-check: БД, миграции, очередь и запись в storage.
 *
 * Запускается по расписанию каждые 15 минут и пишет warning в лог, если что-то
 * не в порядке; можно использовать как ручную проверку окружения или в CI/мониторинге:
 *   php artisan corpms:health --json
 */
class CorpmsHealth extends Command
{
    protected $signature = 'corpms:health
        {--json : Вывести отчёт в JSON}
        {--max-jobs=1000 : Допустимый размер очереди jobs (database)}';

    protected $description = 'Проверить здоровье приложения: БД, миграции, очередь, storage';

    public function handle(): int
    {
        $checks = [
            $this->checkDatabase(),
            $this->checkMigrations(),
            $this->checkQueue(),
            $this->checkStorage(),
        ];

        $healthy = true;

        foreach ($checks as $check) {
            $healthy = $healthy && $check['ok'];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $healthy,
                'checked_at' => now()->toIso8601String(),
                'checks' => $checks,
            ], JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Проверка', 'Статус', 'Детали'],
                array_map(static fn (array $check): array => [
                    $check['name'],
                    $check['ok'] ? '<info>ok</info>' : '<error>fail</error>',
                    $check['details'],
                ], $checks),
            );
        }

        if ($healthy === false) {
            Log::warning('corpms.health.failed', ['checks' => $checks]);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->check('database', true, (string) config('database.default'));
        } catch (Throwable $exception) {
            return $this->check('database', false, $exception->getMessage());
        }
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkMigrations(): array
    {
        try {
            $migrator = app(Migrator::class);
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff(array_keys($files), $ran));

            return $this->check(
                'migrations',
                $pending === [],
                $pending === [] ? 'все применены' : 'ожидают: '.implode(', ', array_slice($pending, 0, 5)),
            );
        } catch (Throwable $exception) {
            return $this->check('migrations', false, $exception->getMessage());
        }
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkQueue(): array
    {
        $connection = (string) config('queue.default');

        if ($connection !== 'database') {
            return $this->check('queue', true, 'backend '.$connection.' — проверка не требуется');
        }

        try {
            $backlog = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
            $max = (int) $this->option('max-jobs');

            return $this->check(
                'queue',
                $backlog <= $max && $failed === 0,
                "в очереди {$backlog} (порог {$max}), failed {$failed}",
            );
        } catch (Throwable $exception) {
            return $this->check('queue', false, $exception->getMessage());
        }
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkStorage(): array
    {
        $paths = [
            'storage' => storage_path(),
            'logs' => storage_path('logs'),
            'app' => storage_path('app'),
        ];

        $notWritable = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $notWritable[] = $label;
            }
        }

        return $this->check(
            'storage',
            $notWritable === [],
            $notWritable === [] ? 'storage, logs, app доступны на запись' : 'нет записи: '.implode(', ', $notWritable),
        );
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function check(string $name, bool $ok, string $details): array
    {
        return ['name' => $name, 'ok' => $ok, 'details' => $details];
    }
}
