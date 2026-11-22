<?php

declare(strict_types=1);

namespace App\Domains\Bi\Jobs;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Bi\Services\BiService;
use App\Models\User;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use App\Support\SingleFlightLock;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** BE-06/PERF-06: пересборка BI-витрины выполняется в очереди. */
final class RebuildBiMart implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public function __construct(
        private readonly int $companyId,
        private readonly int $userId,
        private readonly string $month,
        private readonly string $lockKey,
        private readonly string $lockToken,
    ) {}

    public function handle(
        BiService $bi,
        AuditService $audit,
        PermissionService $permissions,
    ): void {
        try {
            $user = User::find($this->userId);
            if ($user === null
                || $user->employee?->company_id !== $this->companyId
                || ! $permissions->checkScope($user, 'bi.manage', PermissionScope::Company)) {
                return;
            }

            $month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1
                ? $this->month
                : now()->format('Y-m');
            $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
            $from = $start->startOfMonth()->toDateString();
            $to = $start->endOfMonth()->toDateString();

            $written = $bi->rebuild($this->companyId, $from, $to);

            $audit->log($user, 'bi.rebuilt', 'bi_metric', null, null, [
                'month' => $month,
                'rows' => $written,
                'queued' => true,
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
