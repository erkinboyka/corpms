<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ищет задания, зависшие в reserved (воркер упал до finish).
 *
 * По умолчанию только сообщает. С --release возвращает задания в очередь,
 * увеличивая attempts на 1 — после превышения triesLaravel сам уведёт их в failed.
 */
final class QueueWatchdogCommand extends Command
{
    protected $signature = 'queue:watchdog
        {--stale-minutes= : Порог давности reserved_at}
        {--queue= : Проверить только одну очередь}
        {--release : Вернуть зависшие задания в очередь}
        {--dry-run : Только показать, что было бы освобождено}';

    protected $description = 'Обнаружить (и при необходимости освободить) зависшие задания очереди';

    public function handle(): int
    {
        $minutes = max(1, (int) ($this->option('stale-minutes') ?: config('worker.stale_minutes', 15)));
        $threshold = now()->subMinutes($minutes)->timestamp;

        $query = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $threshold);

        $queue = $this->option('queue');
        if (is_string($queue) && $queue !== '') {
            $query->where('queue', $queue);
        }

        $stale = $query->orderBy('reserved_at')->get(['id', 'queue', 'attempts', 'reserved_at', 'payload']);

        if ($stale->isEmpty()) {
            $this->info('Зависших заданий нет.');

            return self::SUCCESS;
        }

        foreach ($stale as $job) {
            $this->warn('#'.$job->id.' ['.$job->queue.'] reserved '.((int) $job->reserved_at).' (attempts '.(int) $job->attempts.')');
        }

        if (! $this->option('release') || $this->option('dry-run')) {
            $this->line('Освобождение не выполнялось (нужен --release без --dry-run).');

            return self::FAILURE;
        }

        $released = 0;
        foreach ($stale as $job) {
            $updated = DB::table('jobs')
                ->where('id', $job->id)
                ->where('reserved_at', $job->reserved_at)
                ->update([
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                    'attempts' => (int) $job->attempts + 1,
                ]);
            $released += $updated;
        }

        Log::warning('corpms.queue_watchdog.released', [
            'count' => $released,
            'stale_minutes' => $minutes,
            'ids' => $stale->pluck('id')->all(),
        ]);
        $this->info('Освобождено заданий: '.$released);

        return $released === $stale->count() ? self::SUCCESS : self::FAILURE;
    }
}
