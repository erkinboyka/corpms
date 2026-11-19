<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Analytics\Services\AnalyticsService;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\BiometricProfile;
use App\Models\Document;
use App\Models\DocumentSignatureRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\DeviceStatus;
use App\Support\PermissionService;
use App\Support\SettingsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Дашборд: роль-зависимые карточки (п.3 ТЗ).
 */
class DashboardController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected PermissionService $permissions,
    ) {}

    public function index(): View
    {
        $user = auth()->user();
        \assert($user instanceof User);

        $employee = $user->employee()->with(['company', 'department', 'position'])->first();
        \assert($employee === null || $employee instanceof Employee);

        $myTasks = Task::query()
            ->where('company_id', $employee?->company_id)
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $employee?->id ?? 0));

        $canViewTasks = $employee !== null && $this->permissions->check($user, 'tasks.view');
        $myTasksByStatus = ! $canViewTasks
            ? []
            : (clone $myTasks)->selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')
                ->map(static fn ($v) => (int) $v)->all();

        $openTasks = (clone $myTasks)->whereNotIn('status', ['completed', 'cancelled']);
        $overdue = ! $canViewTasks ? 0 : (clone $openTasks)
            ->where(fn ($q) => $q->where('status', 'overdue')->orWhere('deadline', '<', now()))
            ->count();

        $upcomingTasks = ! $canViewTasks ? collect() : $openTasks
            ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
            ->orderBy('deadline')
            ->orderByDesc('id')
            ->limit(4)
            ->get(['id', 'title', 'deadline', 'status', 'priority']);

        // посещаемость сегодня (scope по праву analytics.view либо только я)
        $attendance = null;
        $attendanceScopeIds = collect();
        if ($employee !== null && $this->permissions->check($user, 'analytics.view')) {
            $access = $this->analytics->accessibleEmployees($user, 'analytics.view');
            if ($access['employees']->isNotEmpty()) {
                $attendance = $this->analytics->attendanceSummary($access['employees']);
                $attendanceScopeIds = $access['employees']->pluck('id');
            }
        }

        // динамика за 7 дней + тепловая карта за 12 недель (графики дашборда)
        // heatmap — в стиле GitHub: колонка = неделя (пн–вс), выравнивание по понедельникам
        $trend = [];
        $heatmap = null;
        $heatmapTotal = 0;
        $heatmapFrom = null;
        $heatmapTo = now()->toDateString();
        if ($attendanceScopeIds->isNotEmpty()) {
            $today = now()->startOfDay();
            $startWeek = $today->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(11);
            $heatmapFrom = $startWeek->toDateString();

            $rows = AttendanceRecord::query()
                ->whereIn('employee_id', $attendanceScopeIds)
                ->where('date', '>=', $heatmapFrom)
                ->groupBy('date')
                ->selectRaw('date, count(*) as c')
                ->orderBy('date')
                ->get()
                ->keyBy(static fn (AttendanceRecord $r) => (string) $r->getRawOriginal('date'));

            for ($i = 6; $i >= 0; $i--) {
                $day = now()->subDays($i);
                $key = $day->toDateString();
                $trend[] = [
                    'label' => $day->translatedFormat('D'),
                    'date' => $key,
                    'count' => (int) ($rows->get($key)?->c ?? 0),
                ];
            }

            $heatmap = [];
            $prevMonth = null;
            $lastLabelIdx = -99;
            $pendingMonth = null;
            for ($w = 0; $w < 12; $w++) {
                $weekStart = $startWeek->copy()->addWeeks($w);
                $days = [];
                for ($d = 0; $d < 7; $d++) {
                    $day = $weekStart->copy()->addDays($d);
                    if ($day->gt($today)) {
                        $days[] = null; /* будущие дни текущей недели не показываем */

                        continue;
                    }
                    $key = $day->toDateString();
                    $count = (int) ($rows->get($key)?->c ?? 0);
                    $heatmapTotal += $count;
                    $days[] = ['date' => $key, 'count' => $count];
                }
                /* подпись месяца: как в GitHub — над первой неделей нового месяца,
                   но не ближе 2 колонок к предыдущей подписи, иначе подписи слипаются */
                $month = (int) $weekStart->format('n');
                if ($w > 0 && $month !== $prevMonth) {
                    $pendingMonth = $month;
                }
                if ($w === 0) {
                    $lastLabelIdx = 0;
                } elseif ($pendingMonth !== null && $w - $lastLabelIdx >= 2) {
                    $lastLabelIdx = $w;
                    $pendingMonth = null;
                }
                $heatmap[] = [
                    'days' => $days,
                    'label' => $w === 0 || $lastLabelIdx === $w
                        ? $weekStart->translatedFormat('F')
                        : '',
                ];
                $prevMonth = $month;
            }
        }

        // активность всех пользователей (журнал аудита за период тепловой карты)
        // — только своей компании (P0-8: утечка email/активности других тенантов)
        $userActivity = collect();
        $activityTotal = 0;
        $companyId = $employee?->company_id;
        if ($this->permissions->check($user, 'audit.view') && $companyId !== null) {
            $activityFrom = $heatmapFrom ?? now()->subDays(83)->toDateString();
            $byUser = DB::table('audit_logs')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
                ->whereNotNull('audit_logs.user_id')
                ->where('employees.company_id', $companyId)
                ->where('audit_logs.created_at', '>=', $activityFrom)
                ->selectRaw('audit_logs.user_id as id, users.name, users.email, count(*) as cnt, max(audit_logs.created_at) as last_at')
                ->groupBy('audit_logs.user_id', 'users.name', 'users.email')
                ->orderByDesc('cnt')
                ->get()
                ->keyBy('id');

            $activityTotal = (int) $byUser->sum('cnt');
            $userActivity = User::query()
                ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
                ->orderBy('name')
                ->get()
                ->map(static fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'cnt' => (int) ($byUser->get($u->id)->cnt ?? 0),
                    'last_at' => $byUser->get($u->id)->last_at ?? null,
                ])
                ->sortByDesc('cnt')
                ->values();
        }

        // ждёт меня
        $waitingSignatures = DocumentSignatureRequest::query()
            ->where('signer_id', $employee?->id ?? 0)
            ->where('status', 'pending')
            ->count();

        $waitingApprovals = ($employee !== null && $this->permissions->check($user, 'documents.approve'))
            ? Document::query()
                ->where('company_id', $employee->company_id)
                ->whereIn('status', ['submitted', 'review'])
                ->count()
            : 0;

        $pendingLeaves = ($employee !== null && $this->permissions->check($user, 'leaves.approve'))
            ? LeaveRequest::query()
                ->whereHas('employee', fn ($q) => $q->where('company_id', $employee->company_id))
                ->where('status', 'pending')
                ->count()
            : null;

        // моё присутствие сегодня
        $todayRecord = $employee === null
            ? null
            : AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', CarbonImmutable::today()->toDateString())
                ->first();
        \assert($todayRecord === null || $todayRecord instanceof AttendanceRecord);

        // биометрия: сводка только для holders biometry.dashboard.view — своей компании
        $biometrics = null;
        if ($employee !== null && $companyId !== null && $this->permissions->check($user, 'biometry.dashboard.view')) {
            $biometrics = [
                'devices_total' => BiometricDevice::query()->where('company_id', $companyId)->count(),
                'devices_online' => BiometricDevice::query()
                    ->where('company_id', $companyId)
                    ->where('status', DeviceStatus::Online->value)
                    ->where('last_seen_at', '>=', now()->subMinutes((int) app(SettingsService::class)->get('corpms.biometric.offline_after_minutes', 15)))
                    ->count(),
                'profiles' => BiometricProfile::query()
                    ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
                    ->where('status', 'enrolled')
                    ->count(),
                'events_today' => BiometricEvent::query()
                    ->whereHas('device', fn ($q) => $q->where('company_id', $companyId))
                    ->whereDate('occurred_at', CarbonImmutable::today())
                    ->count(),
            ];
        }

        return view('dashboard', compact(
            'employee', 'canViewTasks', 'myTasksByStatus', 'overdue', 'upcomingTasks', 'attendance',
            'waitingSignatures', 'waitingApprovals', 'pendingLeaves', 'todayRecord', 'biometrics',
            'trend', 'heatmap', 'heatmapTotal', 'heatmapFrom', 'heatmapTo',
            'userActivity', 'activityTotal',
        ));
    }
}
