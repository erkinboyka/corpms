<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Queue\Jobs\VerifyWorkerPool;
use App\Support\Queue\WorkerHeartbeat;
use Illuminate\Console\Command;

/**
 * Отправляет canary-задание в каждый пул воркеров.
 * Используется по расписанию и вручную: подтверждает, что воркер исполняет текущий код.
 */
final class WorkerSelfCheckCommand extends Command
{
    protected $signature = 'worker:selfcheck {--pool=* : Имена пулов (по умолчанию все)}';

    protected $description = 'Отправить canary-задание в пулы воркеров для проверки их живости';

    public function handle(WorkerHeartbeat $heartbeat): int
    {
        $known = $heartbeat->pools();
        $requested = (array) $this->option('pool');
        $pools = $requested === [] ? array_keys($known) : array_values(array_unique(array_filter($requested, 'is_string')));
        $invalid = array_diff($pools, array_keys($known));

        if ($invalid !== []) {
            $this->error('Неизвестные пулы: '.implode(', ', $invalid));

            return self::FAILURE;
        }

        foreach ($pools as $pool) {
            VerifyWorkerPool::dispatch($pool);
            $this->info('Canary отправлен в пул '.$pool.' (очередь '.$known[$pool].')');
        }

        return self::SUCCESS;
    }
}
