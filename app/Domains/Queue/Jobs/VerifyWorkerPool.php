<?php

declare(strict_types=1);

namespace App\Domains\Queue\Jobs;

use App\Support\Queue\WorkerHeartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Canary-задание: подтверждает, что воркер пула жив и исполняет текущий код.
 */
final class VerifyWorkerPool implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public function __construct(
        public readonly string $pool,
    ) {
        $queue = (array) config('worker.pools.'.$pool, []);
        $this->onQueue((string) ($queue['queue'] ?? $pool));
    }

    public function handle(WorkerHeartbeat $heartbeat): void
    {
        $heartbeat->beat($this->pool);
    }
}
