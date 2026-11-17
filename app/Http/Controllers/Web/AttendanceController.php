<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Attendance\Services\AttendanceService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\BiometricEvent;
use App\Models\Employee;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Посещаемость в UI (п.27–29 ТЗ): сегодня, Live, табель, аномалии, мой табель.
 */
class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'attendance.view');

        $query = AttendanceRecord::query()
            ->with('employee')
            ->whereDate('date', now())
            // межтенантная изоляция (S2): записи только сотрудников своей компании
            ->whereHas('employee', fn ($q) => $q->where('company_id', $me->company_id));

        if ($scope === PermissionScope::Own) {
            $query->where('employee_id', $me->id);
        } elseif ($scope === PermissionScope::Department) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $me->department_id));
        }

        $records = $query->orderBy('clock_in_at')->get();

        // Live-виджет: последние биометрические события дня (только своей компании)
        $events = BiometricEvent::query()
            ->with(['device', 'employee'])
            ->whereDate('occurred_at', now())
            ->whereHas('device', fn ($q) => $q->where('company_id', $me->company_id))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $statusCounts = $records->groupBy(fn ($r) => $r->status->value)->map->count();

        return view('attendance.index', compact('records', 'events', 'statusCounts', 'scope'));
    }

    /**
     * Live-обновление (JSON): вызывается со страницы посещаемости каждые N секунд,
     * отдаёт последние события и сводку по scope текущего пользователя.
     */
    public function liveFeed(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'attendance.view');

        $query = AttendanceRecord::query()
            ->with('employee')
            ->whereDate('date', now())
            ->whereHas('employee', fn ($q) => $q->where('company_id', $me->company_id));

        if ($scope === PermissionScope::Own) {
            $query->where('employee_id', $me->id);
        } elseif ($scope === PermissionScope::Department) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $me->department_id));
        }

        $records = $query->orderBy('clock_in_at')->get();

        $events = BiometricEvent::query()
            ->with(['device', 'employee'])
            ->whereDate('occurred_at', now())
            ->whereHas('device', fn ($q) => $q->where('company_id', $me->company_id))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $statusCounts = $records->groupBy(fn ($r) => $r->status->value)->map->count();

        return response()->json([
            'counters' => $statusCounts,
            'records' => $records->map(static fn (AttendanceRecord $r) => [
                'employee' => trim(($r->employee->last_name ?? '').' '.($r->employee->first_name ?? '')),
                'clock_in_at' => $r->clock_in_at?->format('H:i') ?? '—',
                'clock_out_at' => $r->clock_out_at?->format('H:i') ?? '—',
                'status' => $r->status->value,
                'worked_minutes' => $r->worked_minutes,
                'late_minutes' => $r->late_minutes,
            ])->values(),
            'events' => $events->map(static fn (BiometricEvent $e) => [
                'direction' => (string) $e->direction,
                'employee' => $e->employee?->last_name ?? 'не опознан',
                'device' => $e->device?->name ?? '—',
                'time' => $e->occurred_at->format('H:i:s'),
                'status' => $e->processing_status->value,
            ])->values(),
            'generated_at' => now()->format('H:i:s'),
        ]);
    }

    /** Табель за месяц (п.28, 33): мой или по выбору сотрудника по scope. */
    public function timesheet(Request $request, ?int $employeeId = null): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'attendance.timesheet.view');

        $selectedId = (int) ($request->input('employee_id') ?? $employeeId ?? $me->id);

        // База выборки: только сотрудники своей компании (анти-IDOR табеля, P0-3).
        $employeeQuery = Employee::query()->where('company_id', $me->company_id);

        if ($scope === PermissionScope::Own && $selectedId !== (int) $me->id) {
            $selectedId = $me->id;
        } elseif ($scope === PermissionScope::Department) {
            $deptIds = Employee::query()
                ->where('company_id', $me->company_id)
                ->where('department_id', $me->department_id)
                ->pluck('id');
            if ($deptIds->contains($selectedId) === false) {
                $selectedId = $me->id;
            }
        }

        // Чужой или несуществующий сотрудник → 404 (без утечки существования).
        $employee = $employeeQuery->findOrFail($selectedId);

        $year = (int) ($request->input('year') ?? now()->year);
        $month = (int) ($request->input('month') ?? now()->month);

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date')
            ->get()->keyBy(fn ($r) => Carbon::parse($r->date)->day);

        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

        $grid = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dayDate = Carbon::create($year, $month, $day);
            $record = $records->get($day);

            $grid[] = [
                'day' => $day,
                'is_off' => in_array($dayDate->dayOfWeekIso, [6, 7], true),
                'record' => $record,
            ];
        }

        $totals = [
            'worked' => (int) $records->sum('worked_minutes'),
            'late' => (int) $records->sum('late_minutes'),
            'overtime' => (int) $records->sum('overtime_minutes'),
            'planned' => (int) $records->sum('scheduled_minutes'),
        ];

        return view('attendance.timesheet', compact('employee', 'grid', 'year', 'month', 'totals', 'scope'));
    }

    /**
     * Экспорт табеля CSV/Excel (п.33, 65.5 ТЗ): /attendance/timesheet/export?year=&month=&employee_id=
     * Область доступа = scope attendance.timesheet.view.
     */
    public function timesheetExport(Request $request): StreamedResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'attendance.timesheet.view');
        \assert($scope !== false, 'маршрут уже проверяет право');

        $year = (int) ($request->input('year') ?? now()->year);
        $month = (int) ($request->input('month') ?? now()->month);
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

        $employeeQuery = Employee::query()->where('company_id', $me->company_id)->where('status', 'active');
        if ($scope === PermissionScope::Own) {
            $employeeQuery->where('id', $me->id);
        } elseif ($scope === PermissionScope::Department) {
            $employeeQuery->where('department_id', $me->department_id);
        }

        if ($request->filled('employee_id')) {
            $employeeQuery->where('id', (int) $request->input('employee_id'));
        }

        $employees = $employeeQuery->orderBy('last_name')->get();

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->groupBy('employee_id');

        $this->audit->log($request->user(), 'report.exported', 'report', null, null, [
            'type' => 'timesheet',
            'year' => $year,
            'month' => $month,
        ]);

        $filename = 'timesheet_'.$year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'.csv';

        return response()->streamDownload(function () use ($employees, $records, $daysInMonth) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM для Excel

            $header = ['Таб.№', 'ФИО'];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $header[] = (string) $d;
            }
            $header[] = 'Итого (ч)';
            fputcsv($out, $header, ';');

            foreach ($employees as $employee) {
                $row = [
                    $employee->employee_no,
                    trim($employee->last_name.' '.$employee->first_name),
                ];
                $perDay = ($records->get($employee->id) ?? collect())->keyBy(fn ($r) => Carbon::parse($r->date)->day);

                for ($d = 1; $d <= $daysInMonth; $d++) {
                    /** @var AttendanceRecord|null $dayRecord */
                    $dayRecord = $perDay->get($d);
                    $row[] = $dayRecord === null
                        ? ''
                        : sprintf('%s–%s', $dayRecord->clock_in_at?->format('H:i') ?? '—', $dayRecord->clock_out_at?->format('H:i') ?? '—');
                }

                $minutes = (int) ($records->get($employee->id) ?? collect())->sum('worked_minutes');
                $row[] = number_format($minutes / 60, 1, ',', '');

                fputcsv($out, $row, ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function anomalies(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'attendance.anomalies.view');

        $query = AttendanceAnomaly::query()
            ->with('employee')
            // межтенантная изоляция (S2): аномалии только своей компании
            ->whereHas('employee', fn ($q) => $q->where('company_id', $me->company_id));

        if ($scope === PermissionScope::Own) {
            $query->where('employee_id', $me->id);
        } elseif ($scope === PermissionScope::Department) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $me->department_id));
        }

        $statusFilter = $request->input('status');
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $anomalies = $query->orderByDesc('date')->orderBy('type')->get();

        $canManage = $this->permissions->check($request->user(), 'attendance.anomalies.manage');

        return view('attendance.anomalies', compact('anomalies', 'canManage', 'statusFilter'));
    }

    /** Разрешение аномалии (корректировка, п.30 ТЗ: HR фиксирует). */
    public function resolveAnomaly(Request $request, AttendanceAnomaly $anomaly): RedirectResponse
    {
        $validated = $request->validate([
            'resolution' => ['required', 'string', 'max:500'],
        ]);

        $anomaly->update([
            'status' => 'resolved',
            'description' => $anomaly->description.' | Решение: '.$validated['resolution'],
        ]);

        return back()->with('status', 'Аномалия разобрана (п.30 ТЗ)');
    }
}
