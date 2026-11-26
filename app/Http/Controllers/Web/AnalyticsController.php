<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Analytics\Services\AnalyticsService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PermissionService;
use Illuminate\View\View;

/**
 * Аналитика (п.63 ТЗ).
 */
class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected AuditService $audit,
        protected PermissionService $permissions,
    ) {}

    public function index(): View
    {
        $user = auth()->user();
        \assert($user instanceof User);

        $access = $this->analytics->accessibleEmployees($user, 'analytics.view');
        $employees = $access['employees'];

        // журнал действий показываем только владельцам audit.view (как на дашборде)
        $canAudit = $this->permissions->check($user, 'audit.view');
        $companyId = (int) $user->employee->company_id;

        return view('analytics.index', [
            'scope' => $access['scope_value'],
            'attendance' => $this->analytics->attendanceSummary($employees),
            'trend' => $this->analytics->attendanceTrend($employees),
            'tasks' => $this->analytics->taskStats($employees),
            'documents' => $this->analytics->documentStats($employees),
            'leaves' => $this->analytics->leaveStats($employees),
            'headcount' => $this->analytics->headcount($employees),
            'departments' => $this->analytics->hrByDepartment($employees),
            // виджеты (п.3)
            'priorities' => $this->analytics->taskPriorityStats($employees),
            'deadlines' => $this->analytics->taskDeadlineStats($employees),
            'completion' => $this->analytics->taskCompletion($employees),
            'assignees' => $this->analytics->topAssignees($employees),
            'tenure' => $this->analytics->tenureStats($employees),
            'timeTracking' => $this->analytics->timeTrackingStats($employees),
            'canAudit' => $canAudit,
            'auditTrend' => $canAudit ? $this->analytics->auditTrend(14, $companyId) : null,
            'auditActions' => $canAudit ? $this->analytics->auditTopActions(14, $companyId) : [],
        ]);
    }
}
