<?php

declare(strict_types=1);

namespace App\Domains\Backup\Jobs;

use App\Domains\Audit\Services\AuditService;
use App\Models\User;
use App\Support\PermissionService;
use App\Support\SingleFlightLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/** BE-06/PERF-06: архив вложений создаётся в очереди, один на всю инсталляцию. */
final class RunFilesBackup implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public function __construct(
        private readonly int $userId,
        private readonly string $lockKey,
        private readonly string $lockToken,
    ) {}

    public function handle(AuditService $audit, PermissionService $permissions): void
    {
        try {
            $user = User::find($this->userId);
            if ($user === null || ! $permissions->roleNames($user)->contains('super_admin')) {
                return;
            }

            Artisan::call('files:backup', ['--json' => true]);
            $output = trim(Artisan::output());

            $audit->log($user, 'backup.created', 'backup', null, null, [
                'kind' => 'files',
                'queued' => true,
                'output' => $output,
            ]);
        } finally {
            SingleFlightLock::release($this->lockKey, $this->lockToken);
        }
    }

    public function failed(Throwable $exception): void
    {
        SingleFlightLock::release($this->lockKey, $this->lockToken);
    }
}
