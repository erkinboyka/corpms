<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskTimeLog;
use App\Models\User;
use App\Shared\Enums\EmployeeStatus;
use App\Support\PermissionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика (п.63 ТЗ): сводные показатели по посещаемости, задачам, документам и кадрам.
 * Все выборки автоматически ограничены scope'ом права вызывающего.
 */
class AnalyticsService
{
    public function __construct(
        protected PermissionService $permissions,
    ) {}

    /**
     * ID сотрудников, доступных по scope права (null = вся компания).
     *
     * @return array{scope_value: string, employees: Collection<int, Employee>}
     */
    public function accessibleEmployees(User $user, string $permission): array
    {
        $scope = $this->permissions->can($user, $permission);
        \assert($scope !== false, 'право нужно проверить до вызова');

        $me = $this->permissions->employee($user);
        \assert($me !== null);

        $query = Employee::query()->where('company_id', $me->company_id);

        $employees = match ($scope->value) {
            'company' => $query->where('status', 'active')->orderBy('last_name')->get(),
            'department' => $query->where('status', 'active')
                ->where('department_id', $me->department_id)
                ->orderBy('last_name')
                ->get()
                ->when($me->department_id === null, fn ($c) => collect([$me])),
            default => collect([$me]),
        };

        return ['scope_value' => $scope->value, 'employees' => $employees];
    }

    /** Срез посещаемости за дату. */
    public function attendanceSummary(Collection $employees, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return ['total' => 0, 'present' => 0, 'late' => 0, 'remote' => 0, 'absent_from_schedule' => 0];
        }

        $records = AttendanceRecord::query()
            ->whereDate('date', $date->toDateString())
            ->whereIn('employee_id', $ids)
            ->get();

        $present = 0;
        $late = 0;
        $remote = 0;
        foreach ($records as $record) {
            $status = $record->status->value;
            if ($status === 'remote') {
                $remote++;
            } elseif ($record->late_minutes > 0 || $status === 'late') {
                $late++;
            } elseif ($record->clock_in_at !== null) {
                $present++;
            }
        }

        return [
            'total' => $employees->count(),
            'present' => $present,
            'late' => $late,
            'remote' => $remote,
            'absent_from_schedule' => max($employees->count() - $records->count(), 0),
        ];
    }

    /** Тренд посещаемости по дням (посл. N дней). */
    public function attendanceTrend(Collection $employees, int $days = 14): array
    {
        $ids = $employees->pluck('id')->all();
        $from = CarbonImmutable::today()->subDays($days - 1);

        $rows = $ids === []
            ? collect()
            : AttendanceRecord::query()
                ->whereIn('employee_id', $ids)
                ->whereDate('date', '>=', $from->toDateString())
                ->get();

        $byDate = $rows->groupBy(fn ($r) => $r->date instanceof CarbonInterface ? $r->date->toDateString() : (string) $r->date);

        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i);
            $records = $byDate->get($day->toDateString(), collect());

            $trend[] = [
                'date' => $day,
                'present' => $records->filter(fn ($r) => $r->late_minutes > 0 || $r->status->value === 'late' ? false : $r->clock_in_at !== null && $r->status->value !== 'remote')->count(),
                'late' => $records->filter(fn ($r) => $r->late_minutes > 0 || $r->status->value === 'late')->count(),
                'remote' => $records->filter(fn ($r) => $r->status->value === 'remote')->count(),
            ];
        }

        return $trend;
    }

    /** Задачи по статусам в scope. */
    public function taskStats(Collection $employees): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $rows = Task::query()
            ->selectRaw('status, count(*) as cnt')
            ->whereHas('assignments', fn ($q) => $q->whereIn('employee_id', $ids))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        // SVG-статистика без ленивой связи: overdue — статус, просрочка без статуса тоже стата
        $overdue = Task::query()
            ->whereHas('assignments', fn ($q) => $q->whereIn('employee_id', $ids))
            ->where('deadline', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled', 'overdue'])
            ->count();

        $result = [];
        foreach ($rows as $status => $cnt) {
            $result[(string) $status] = (int) $cnt;
        }
        $result['overdue'] = ($result['overdue'] ?? 0) + $overdue;

        return $result;
    }

    /** Документы по статусам в scope. */
    public function documentStats(Collection $employees): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return Document::query()
            ->selectRaw('status, count(*) as cnt')
            ->where(fn ($q) => $q->whereIn('author_id', $ids)
                ->orWhereHas('signatureRequests', fn ($r) => $r->whereIn('signer_id', $ids)))
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->map(static fn ($v) => (int) $v)
            ->all();
    }

    /** Кадровый срез: численность по отделам. */
    public function hrByDepartment(Collection $employees): array
    {
        $byDept = $employees->groupBy('department_id');

        $departaments = Department::query()
            ->whereIn('id', $byDept->keys()->filter()->all())
            ->with('director')
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($byDept as $deptId => $group) {
            $departament = $departaments->get($deptId);
            $result[] = [
                'department' => $departament,
                'name' => $departament?->name ?? 'Без подразделения',
                'headcount' => $group->count(),
                'director' => $departament?->director?->last_name,
            ];
        }

        return collect($result)->sortByDesc('headcount')->values()->all();
    }

    /** Заявки на отпуск по статусам. */
    public function leaveStats(Collection $employees): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return LeaveRequest::query()
            ->selectRaw('status, count(*) as cnt')
            ->whereIn('employee_id', $ids)
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->map(static fn ($v) => (int) $v)
            ->all();
    }

    /** Численность: активные / в отпуске / все. */
    public function headcount(Collection $employees): array
    {
        return [
            'active' => $employees->filter(fn ($e) => $e->status->value === EmployeeStatus::Active->value)->count(),
            'total' => $employees->count(),
        ];
    }

    /* ========================================================================
       Виджеты аналитики (п.3): все выборки в scope вызывающего.
       ======================================================================== */

    /** Задачи по приоритетам в scope. */
    public function taskPriorityStats(Collection $employees): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return Task::query()
            ->selectRaw('priority, count(*) as cnt')
            ->whereHas('assignments', fn ($q) => $q->whereIn('employee_id', $ids))
            ->groupBy('priority')
            ->pluck('cnt', 'priority')
            ->map(static fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Горизонт сроков открытых задач: срок прошёл / ближайшие 7 дней /
     * позже / без срока. Считается по дате, а не по статусу.
     *
     * @return array{overdue: int, week: int, later: int, none: int}
     */
    public function taskDeadlineStats(Collection $employees): array
    {
        $out = ['overdue' => 0, 'week' => 0, 'later' => 0, 'none' => 0];
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return $out;
        }

        $deadlines = Task::query()
            ->whereHas('assignments', fn ($q) => $q->whereIn('employee_id', $ids))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->pluck('deadline');

        $week = now()->addDays(7);
        foreach ($deadlines as $deadline) {
            if ($deadline === null) {
                $out['none']++;

                continue;
            }
            $date = $deadline instanceof CarbonInterface ? $deadline : Carbon::parse($deadline);
            if ($date->isPast()) {
                $out['overdue']++;
            } elseif ($date->lte($week)) {
                $out['week']++;
            } else {
                $out['later']++;
            }
        }

        return $out;
    }

    /** Доля выполненных задач: completed / (completed + открытые), без двойного счёта. */
    public function taskCompletion(Collection $employees): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return ['completed' => 0, 'active' => 0, 'pct' => 0];
        }

        $rows = Task::query()
            ->selectRaw('status, count(*) as cnt')
            ->whereHas('assignments', fn ($q) => $q->whereIn('employee_id', $ids))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $completed = (int) ($rows['completed'] ?? 0);
        $active = 0;
        foreach ($rows as $status => $cnt) {
            if (! in_array((string) $status, ['completed', 'cancelled'], true)) {
                $active += (int) $cnt;
            }
        }
        $total = $completed + $active;

        return [
            'completed' => $completed,
            'active' => $active,
            'pct' => $total > 0 ? (int) round(100 * $completed / $total) : 0,
        ];
    }

    /** Топ исполнителей по открытым задачам (задача считается один раз). */
    public function topAssignees(Collection $employees, int $limit = 6): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $rows = TaskAssignment::query()
            ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->join('employees', 'employees.id', '=', 'task_assignments.employee_id')
            ->whereIn('task_assignments.employee_id', $ids)
            ->whereNotIn('tasks.status', ['completed', 'cancelled'])
            ->groupBy('task_assignments.employee_id', 'employees.first_name', 'employees.last_name')
            ->selectRaw('task_assignments.employee_id as id, employees.first_name, employees.last_name, count(distinct tasks.id) as cnt')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $rows->map(static fn ($row) => [
            'id' => (int) $row->id,
            'name' => trim($row->last_name.' '.$row->first_name),
            'count' => (int) $row->cnt,
        ])->all();
    }

    /** Стажевые группы сотрудников scope (по дате приёма). */
    public function tenureStats(Collection $employees): array
    {
        $buckets = ['<1 года' => 0, '1–3 года' => 0, '3–5 лет' => 0, '5+ лет' => 0, 'не указан' => 0];

        foreach ($employees as $employee) {
            if ($employee->hired_at === null) {
                $buckets['не указан']++;

                continue;
            }
            $years = (int) floor($employee->hired_at->diffInYears(now()));
            if ($years < 1) {
                $buckets['<1 года']++;
            } elseif ($years < 3) {
                $buckets['1–3 года']++;
            } elseif ($years < 5) {
                $buckets['3–5 лет']++;
            } else {
                $buckets['5+ лет']++;
            }
        }

        return $buckets;
    }

    /** Учёт времени на задачах scope: сумма минут и записи. */
    public function timeTrackingStats(Collection $employees): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return ['minutes' => 0, 'records' => 0];
        }

        $row = TaskTimeLog::query()
            ->whereIn('employee_id', $ids)
            ->selectRaw('coalesce(sum(minutes), 0) as m, count(*) as c')
            ->first();

        return [
            'minutes' => (int) ($row->m ?? 0),
            'records' => (int) ($row->c ?? 0),
        ];
    }

    /**
     * События журнала аудита по дням за период.
     * Вызывать только после проверки права audit.view (как на дашборде).
     *
     * @return array{days: array<int, array{date: CarbonImmutable, count: int}>, total: int}
     */
    public function auditTrend(int $days = 14): array
    {
        $from = CarbonImmutable::today()->subDays($days - 1);
        $rows = DB::table('audit_logs')
            ->where('created_at', '>=', $from->toDateTimeString())
            ->selectRaw('DATE(created_at) as d, count(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $trend = [];
        $total = 0;
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i);
            $count = (int) ($rows->get($day->toDateString()) ?? 0);
            $total += $count;
            $trend[] = ['date' => $day, 'count' => $count];
        }

        return ['days' => $trend, 'total' => $total];
    }

    /** Самые частые действия журнала за период (для владельцев audit.view). */
    public function auditTopActions(int $days = 14, int $limit = 6): array
    {
        $from = CarbonImmutable::today()->subDays($days - 1);

        return DB::table('audit_logs')
            ->where('created_at', '>=', $from->toDateTimeString())
            ->selectRaw('action, count(*) as c')
            ->groupBy('action')
            ->orderByDesc('c')
            ->limit($limit)
            ->get()
            ->map(static fn ($row) => ['action' => (string) $row->action, 'count' => (int) $row->c])
            ->all();
    }
}
