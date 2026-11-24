<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Jobs;

use App\Domains\Audit\Services\AuditService;
use App\Domains\DashboardGen\Services\AiClient;
use App\Domains\DashboardGen\Services\DashboardGenerator;
use App\Domains\DashboardGen\Services\SchemaInspector;
use App\Domains\DashboardGen\Services\WidgetCatalog;
use App\Models\BiDashboard;
use App\Support\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Генерация дашборда ИИ в очереди (аналог RebuildBiMart для BI-генерации):
 * пользователь не ждёт несколько вызовов модели в HTTP-запросе.
 */
final class GenerateAiDashboard implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public function __construct(
        private readonly int $dashboardId,
        private readonly int $companyId,
        private readonly int $userId,
        private readonly string $instruction,
    ) {}

    public function handle(): void
    {
        $dashboard = BiDashboard::query()->find($this->dashboardId);

        if ($dashboard === null || $dashboard->company_id !== $this->companyId) {
            return;
        }

        if ($dashboard->origin !== 'ai' || $dashboard->status !== 'generating') {
            return;
        }

        $settings = app(SettingsService::class);
        $settings->useCompany($this->companyId);

        try {
            $generator = new DashboardGenerator(
                app(AiClient::class),
                app(\App\Domains\DashboardGen\Services\DashboardPlanAi::class),
                app(WidgetCatalog::class),
                app(SchemaInspector::class),
            );

            $generator->generate($dashboard, $this->instruction, $this->companyId);

            $user = \App\Models\User::query()->find($this->userId);

            if ($user !== null) {
                app(AuditService::class)->log($user, 'bi.dashboard_generated', 'bi_dashboard', $dashboard->id, null, [
                    'instruction' => $this->instruction,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('DashboardGen: генерация дашборда не удалась', [
                'dashboard_id' => $this->dashboardId,
                'error' => $e->getMessage(),
            ]);

            $dashboard->refresh();

            $dashboard->update([
                'status' => 'error',
                'last_error' => \Illuminate\Support\Str::limit($e->getMessage(), 1000, ''),
            ]);

            throw $e;
        }
    }
}
