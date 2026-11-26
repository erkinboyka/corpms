<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Queue\WorkerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Состояние пулов воркеров: heartbeat, очередь, failed jobs, зависшие задания.
 *
 *   php artisan queue:health --json
 */
final class QueueHealthCommand extends Command
{
    protected $signature = 'queue:health
        {--json : Вывести отчёт в JSON}
        {--stale-minutes= : Считать задание зависшим после N минут в reserved}
        {--max-jobs=1000 : Допустимый backlog на пул}';

    protected $description = 'Проверить heartbeat воркеров, backlog и зависшие задания по пулам';

    public function handle(WorkerHeartbeat $heartbeat): int
    {
        $staleMinutes = max(1, (int) ($this->option('stale-minutes') ?: config('worker.stale_minutes', 15)));
        $maxJobs = max(0, (int) $this->option('max-jobs'));
        $threshold = now()->subMinutes($staleMinutes)->timestamp;
        $rows = [];
        $healthy = true;

        foreach ($heartbeat->pools() as $pool => $queue) {
            $state = $heartbeat->status($pool);

            try {
                $backlog = (int) DB::table('jobs')->where('queue', $queue)->count();
                $failed = (int) DB::table('failed_jobs')->where('queue', $queue)->count();
                $stale = (int) DB::table('jobs')
                    ->where('queue', $queue)
                    ->whereNotNull('reserved_at')
                    ->where('reserved_at', '<', $threshold)
                    ->count();
            } catch (Throwable $exception) {
                $backlog = $failed = $stale = 0;
                $state['fresh'] = false;
                $state['error'] = $exception->getMessage();
            }

            $ok = $state['fresh'] && $backlog <= $maxJobs && $failed === 0 && $stale === 0;
            $healthy = $healthy && $ok;

            $rows[] = [
                'pool' => $pool,
                'queue' => $queue,
                'ok' => $ok,
                'heartbeat' => $state,
                'backlog' => $backlog,
                'failed' => $failed,
                'stale' => $stale,
            ];
        }

        $report = [
            'ok' => $healthy,
            'checked_at' => now()->toIso8601String(),
            'stale_minutes' => $staleMinutes,
            'pools' => $rows,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Пул', 'Очередь', 'Heartbeat', 'Backlog', 'Failed', 'Stale'],
                array_map(static fn (array $row): array => [
                    $row['pool'],
                    $row['queue'],
                    $row['ok'] ? '<info>ok</info>' : '<error>fail</error>',
                    (string) $row['backlog'],
                    (string) $row['failed'],
                    (string) $row['stale'],
                ], $rows),
            );
        }

        if (! $healthy) {
            Log::warning('corpms.queue_health.failed', ['pools' => $rows]);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
