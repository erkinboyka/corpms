<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Analytics\Services\AnalyticsService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Support\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Отчёты (п.65 ТЗ): кадры, посещаемость, задачи + экспорт (п.65.5).
 */
class ReportController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    public function index(): View
    {
        [$employees, $scope] = $this->accessible('reports.view');

        return view('reports.index', [
            'scope' => $scope,
            'headcount' => $this->analytics->headcount($employees),
            'attendanceToday' => $this->analytics->attendanceSummary($employees),
            'taskDeadlines' => $this->analytics->taskDeadlineStats($employees),
            'taskCompletion' => $this->analytics->taskCompletion($employees),
        ]);
    }

    /** Кадровый отчёт: численность по подразделениям. */
    public function hr(): View
    {
        [$employees, $scope] = $this->accessible('employees.view');

        return view('reports.hr', [
            'scope' => $scope,
            'headcount' => $this->analytics->headcount($employees),
            'departments' => $this->analytics->hrByDepartment($employees),
        ]);
    }

    /** Отчёт по посещаемости за период. */
    public function attendance(): View
    {
        [$employees, $scope] = $this->accessible('analytics.view');
        [$from, $to] = $this->period();

        $ids = $employees->pluck('id')->all();

        $rows = $ids === [] ? collect() : AttendanceRecord::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('employee_id, count(*) as days_worked')
            ->selectRaw('sum(late_minutes) as late_total')
            ->selectRaw('sum(early_leave_minutes) as early_total')
            ->selectRaw('sum(overtime_minutes) as overtime_total')
            ->selectRaw('sum(case when clock_out_at is null then 1 else 0 end) as missing_checkout')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $result = $employees->map(static function (Employee $e) use ($rows) {
            $row = $rows->get($e->id);

            return [
                'employee' => $e,
                'days_worked' => (int) ($row->days_worked ?? 0),
                'late_total' => (int) ($row->late_total ?? 0),
                'early_total' => (int) ($row->early_total ?? 0),
                'overtime_total' => (int) ($row->overtime_total ?? 0),
                'missing_checkout' => (int) ($row->missing_checkout ?? 0),
            ];
        });

        return view('reports.attendance', [
            'scope' => $scope, 'rows' => $result, 'from' => $from, 'to' => $to,
        ]);
    }

    /** Отчёт по задачам за период. */
    public function tasks(): View
    {
        [$employees, $scope] = $this->accessible('analytics.view');
        [$from, $to] = $this->period();

        $ids = $employees->pluck('id')->all();

        $rows = $ids === [] ? collect() : Task::query()
            ->selectRaw('tasks.status, task_assignments.employee_id as emp_id, count(distinct tasks.id) as cnt')
            ->join('task_assignments', 'task_assignments.task_id', '=', 'tasks.id')
            ->whereIn('task_assignments.employee_id', $ids)
            ->whereDate('tasks.created_at', '>=', $from->toDateString())
            ->whereDate('tasks.created_at', '<=', $to->toDateString())
            ->groupBy('tasks.status', 'task_assignments.employee_id')
            ->get();

        $perEmployee = $rows->groupBy('emp_id')->map(static fn (Collection $g, $empId) => [
            'employee' => $employees->firstWhere('id', (int) $empId),
            'total' => $g->sum('cnt'),
            'completed' => (int) $g->firstWhere('status', 'completed')?->cnt,
            'in_progress' => (int) ($g->firstWhere('status', 'in_progress')?->cnt ?? 0),
            'overdue' => (int) ($g->firstWhere('status', 'overdue')?->cnt ?? 0),
        ])->values();

        return view('reports.tasks', [
            'scope' => $scope, 'rows' => $perEmployee, 'from' => $from, 'to' => $to,
        ]);
    }

    /** Экспорт CSV / Excel (п.65.5): /reports/{type}/export?from=&to= (type in: hr, attendance, tasks). */
    public function export(string $type): StreamedResponse
    {
        if (! in_array($type, ['hr', 'attendance', 'tasks'], true)) {
            abort(404);
        }

        [$employees, $scope] = $this->accessible($type === 'hr' ? 'employees.view' : 'analytics.view');
        [$from, $to] = $this->period();

        $user = auth()->user();
        \assert($user instanceof User);
        $this->audit->log($user, 'report.exported', 'report', null, null, ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        $filename = $type.'_'.$from->toDateString().'_'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($type, $employees, $from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM для Excel
            match ($type) {
                'attendance' => $this->csvAttendance($employees, $from, $to),
                'tasks' => $this->csvTasks($employees, $from, $to),
                default => $this->csvHr($employees),
            };
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* -------------------- helpers -------------------- */

    /** @return array{0: Collection<int, Employee>, 1: string} */
    protected function accessible(string $permission): array
    {
        $user = auth()->user();
        \assert($user instanceof User);

        $reportPermission = request()->routeIs('reports.export') ? 'reports.export' : 'reports.view';
        abort_unless($this->permissions->check($user, $permission) && $this->permissions->check($user, $reportPermission), 403);
        $access = $this->analytics->accessibleEmployees($user, $permission);
        $reportAccess = $this->analytics->accessibleEmployees($user, $reportPermission);
        $allowedIds = $reportAccess['employees']->pluck('id');
        $employees = $access['employees']->whereIn('id', $allowedIds)->values();
        $sourceScope = $this->permissions->can($user, $permission);
        $reportScope = $this->permissions->can($user, $reportPermission);

        return [$employees, $sourceScope->rank() < $reportScope->rank() ? $sourceScope->value : $reportScope->value];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function period(): array
    {
        $fromRaw = trim((string) request('from', ''));
        $toRaw = trim((string) request('to', ''));

        $fallbackFrom = CarbonImmutable::today()->firstOfMonth();
        $fallbackTo = CarbonImmutable::today();

        try {
            $from = $fromRaw !== '' ? CarbonImmutable::parse($fromRaw) : $fallbackFrom;
        } catch (\Throwable) {
            $from = $fallbackFrom;
        }
        try {
            $to = $toRaw !== '' ? CarbonImmutable::parse($toRaw) : $fallbackTo;
        } catch (\Throwable) {
            $to = $fallbackTo;
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    protected function csvHr(Collection $employees): void
    {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Подразделение', 'Руководитель', 'Численность'], ';');
        foreach ($this->analytics->hrByDepartment($employees) as $row) {
            fputcsv($out, [$row['name'], $row['director'] ?? '—', $row['headcount']], ';');
        }
        fclose($out);
    }

    protected function csvAttendance(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Таб.№', 'ФИО', 'Дней отработано', 'Опоздания (мин)', 'Ранние уходы (мин)', 'Сверхурочные (мин)', 'Незакрытых смен'], ';');

        // один агрегатный запрос на всех сотрудников (без N+1 по строкам экспорта)
        $ids = $employees->pluck('id')->all();
        $aggregates = $ids === [] ? collect() : AttendanceRecord::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('employee_id, count(*) c, sum(late_minutes) late, sum(early_leave_minutes) early, sum(overtime_minutes) over_sum, sum(case when clock_out_at is null then 1 else 0 end) open')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        foreach ($employees as $employee) {
            $row = $aggregates->get($employee->id);
            fputcsv($out, [
                $employee->employee_no,
                trim($employee->last_name.' '.$employee->first_name),
                (int) ($row->c ?? 0),
                (int) ($row->late ?? 0),
                (int) ($row->early ?? 0),
                (int) ($row->over_sum ?? 0),
                (int) ($row->open ?? 0),
            ], ';');
        }
        fclose($out);
    }

    protected function csvTasks(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Таб.№', 'ФИО', 'Всего', 'Завершено', 'В работе', 'Просрочено'], ';');

        // вместо 4 count-запросов на каждого сотрудника — одна группировка
        $ids = $employees->pluck('id')->all();
        $rows = $ids === [] ? collect() : Task::query()
            ->selectRaw('tasks.status, task_assignments.employee_id as emp_id, count(distinct tasks.id) as cnt')
            ->join('task_assignments', 'task_assignments.task_id', '=', 'tasks.id')
            ->whereIn('task_assignments.employee_id', $ids)
            ->whereDate('tasks.created_at', '>=', $from->toDateString())
            ->whereDate('tasks.created_at', '<=', $to->toDateString())
            ->groupBy('tasks.status', 'task_assignments.employee_id')
            ->get()
            ->groupBy('emp_id');

        foreach ($employees as $employee) {
            $group = $rows->get($employee->id, collect());
            fputcsv($out, [
                $employee->employee_no,
                trim($employee->last_name.' '.$employee->first_name),
                (int) $group->sum('cnt'),
                (int) $group->firstWhere('status', 'completed')?->cnt,
                (int) ($group->firstWhere('status', 'in_progress')?->cnt ?? 0),
                (int) ($group->firstWhere('status', 'overdue')?->cnt ?? 0),
            ], ';');
        }
        fclose($out);
    }
}
