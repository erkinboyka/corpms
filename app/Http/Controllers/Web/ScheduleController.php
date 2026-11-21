<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Workflow\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\WorkSchedule;
use App\Shared\Enums\PermissionScope;
use App\Support\EnumLabels;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * Рабочие графики + праздники (п.16–19 ТЗ) и заявки на отпуск (п.20–23 ТЗ).
 *
 * Согласование отпуска доступно:
 *  - руководителю отдела (dept-scope, 'leaves.approve');
 *  - HR/директору (company-scope, 'leaves.approve').
 */
class ScheduleController extends Controller
{
    public function __construct(
        protected PermissionService $permissions,
        protected AuditService $audit,
        protected WorkflowEngine $engine,
    ) {}

    /* ---------------- графики ---------------- */

    public function schedules(Request $request): View
    {
        $me = $request->user()->employee;
        // company_id null → выборка IS NULL (безопаснее, чем пустой or-вариант нефильтра)
        $schedules = WorkSchedule::query()
            ->where('company_id', $me?->company_id)
            ->with('company')
            ->orderBy('name')
            ->get();
        $canManage = $this->permissions->check($request->user(), 'schedules.manage');

        return view('schedules.index', compact('schedules', 'canManage'));
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        \assert($employee !== null);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', 'in:standard,shift,flexible'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s'],
            'break_start' => ['nullable', 'date_format:H:i:s'],
            'break_end' => ['nullable', 'date_format:H:i:s'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'min_rest_hours' => ['nullable', 'integer', 'min:0', 'max:24'],
        ]);

        $validated['company_id'] = $employee->company_id;

        $schedule = WorkSchedule::create($validated);

        $this->audit->log($request->user(), 'schedule.created', 'work_schedule', $schedule->id, null, $validated);

        return back()->with('status', __('График создан'));
    }

    public function updateSchedule(Request $request, WorkSchedule $schedule): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($schedule, $me);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', 'in:standard,shift,flexible'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s'],
            'break_start' => ['nullable', 'date_format:H:i:s'],
            'break_end' => ['nullable', 'date_format:H:i:s'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'min_rest_hours' => ['nullable', 'integer', 'min:0', 'max:24'],
            'allow_overtime' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $keys = ['name', 'type', 'start_time', 'end_time', 'break_start', 'break_end', 'grace_minutes', 'min_rest_hours', 'allow_overtime', 'is_active'];
        $old = $schedule->only($keys);

        $schedule->update($validated);

        $this->audit->log($request->user(), 'schedule.updated', 'work_schedule', $schedule->id, $old, $validated);

        return back()->with('status', __('График обновлён'));
    }

    /** График с назначенными сотрудниками нельзя удалить тихой сменой порядка — сначала снять назначения. */
    public function destroySchedule(Request $request, WorkSchedule $schedule): RedirectResponse
    {
        TenantGuard::ensure($schedule, $request->user()->employee);

        if (ScheduleAssignment::query()->where('work_schedule_id', $schedule->id)->exists()) {
            abort(403, __('Нельзя удалить график: он назначен сотрудникам'));
        }

        $old = $schedule->only(['name', 'type', 'start_time', 'end_time']);

        $schedule->delete();

        $this->audit->log($request->user(), 'schedule.deleted', 'work_schedule', $schedule->id, $old, null);

        return back()->with('status', __('График удалён'));
    }

    public function assignSchedule(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'work_schedule_id' => ['required', 'exists:work_schedules,id'],
            'employee_ids' => ['array', 'min:1'],
            'employee_ids.*' => ['exists:employees,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        // и график, и сотрудники — только из компании текущего пользователя
        TenantGuard::ensureReference('work_schedules', (int) $validated['work_schedule_id'], $me);

        foreach ($validated['employee_ids'] as $employeeId) {
            TenantGuard::ensureReference('employees', (int) $employeeId, $me, __('Сотрудник из другой компании'));

            ScheduleAssignment::create([
                'work_schedule_id' => $validated['work_schedule_id'],
                'employee_id' => $employeeId,
                'effective_from' => $validated['effective_from'],
                'effective_to' => $validated['effective_to'] ?? null,
            ]);
        }

        $this->audit->log($request->user(), 'schedule.assigned', 'work_schedule', (int) $validated['work_schedule_id'], null, [
            'employees' => $validated['employee_ids'],
            'effective_from' => $validated['effective_from'],
        ]);

        return back()->with('status', __('График назначен (п.21 ТЗ)'));
    }

    /* ---------------- праздники ---------------- */

    public function holidays(Request $request): View
    {
        $me = $request->user()->employee;
        $year = (int) ($request->input('year') ?? now()->year);
        $holidays = Holiday::query()
            ->where('company_id', $me?->company_id)
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get();
        $canManage = $this->permissions->check($request->user(), 'holidays.manage');

        return view('schedules.holidays', compact('holidays', 'year', 'canManage'));
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        \assert($employee !== null);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'date' => ['required', 'date', 'unique:holidays,company_id,date'],
            'kind' => ['required', 'in:public,operating,shortened'],
            'transferred_from' => ['nullable', 'date'],
        ]);

        $validated['company_id'] = $employee->company_id;

        // вид праздника определяет, является день выходным
        $validated['is_day_off'] = $validated['kind'] !== 'operating';

        $holiday = Holiday::create($validated);

        $this->audit->log($request->user(), 'holiday.created', 'holiday', $holiday->id, null, $validated);

        return back()->with('status', __('Праздник добавлен'));
    }

    public function destroyHoliday(Request $request, Holiday $holiday): RedirectResponse
    {
        TenantGuard::ensure($holiday, $request->user()->employee);

        $old = $holiday->only(['name', 'date']);

        $holiday->delete();

        $this->audit->log($request->user(), 'holiday.deleted', 'holiday', $holiday->id, $old, null);

        return back()->with('status', __('Праздник удалён'));
    }

    /* ---------------- отпуска ---------------- */

    public function leaves(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'leaves.view');
        $canApprove = $this->permissions->checkScope($request->user(), 'leaves.approve', PermissionScope::Department);

        $query = LeaveRequest::query()->with(['employee', 'approver']);

        if ($scope === PermissionScope::Own) {
            $query->where('employee_id', $me->id);
        } elseif ($scope === PermissionScope::Department) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $me->department_id));
        }

        $statusFilter = $request->input('status');
        $baseQuery = (clone $query);
        if ($statusFilter !== null && $statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        // счётчики по статусам в рамках scope (без фильтра) — для метрик страницы
        $counts = $baseQuery
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        $leaves = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $types = ['vacation', 'sick', 'business_leave', 'day_off', 'other'];

        return view('schedules.leaves', compact('leaves', 'canApprove', 'scope', 'types', 'statusFilter', 'counts'));
    }

    public function requestLeave(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'type' => ['required', 'in:vacation,sick,business_leave,day_off,other'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $employeeId = (int) ($request->input('employee_id') ?? $me->id);

        // заявка на себя не требует approve; на другого — только HR/руководитель
        if ($employeeId !== (int) $me->id) {
            if ($this->permissions->check($request->user(), 'leaves.approve') === false) {
                throw new LogicException(__('Создать заявку можно только на себя'));
            }

            // и даже HR/руководитель не создаёт заявку сотруднику другой компании
            TenantGuard::ensureReference('employees', $employeeId, $me, __('Сотрудник из другой компании'));
        }

        $leave = LeaveRequest::create([
            'employee_id' => $employeeId,
            'type' => $validated['type'],
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
            'reason' => $validated['reason'] ?? null,
            'status' => 'pending',
        ]);
        $this->engine->record(WorkflowEngine::SUBJECT_LEAVE, $leave->id, null, 'pending', 'request', $request->user(), null);

        $this->audit->log($request->user(), 'leave.requested', 'leave_request', $leave->id, null, [
            'type' => $validated['type'],
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
        ]);

        return back()->with('status', __('Заявка отправлена руководителю'));
    }

    public function decideLeave(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensureLeave($leave, $me);

        $decision = $request->input('decision') === 'approved' ? 'approved' : 'rejected';

        // --- Scope-проверка согласования (раньше здесь был пустой if) ---
        // company-scope (HR/директор) — любая заявка;
        // department-scope (руководитель отдела) — только заявки сотрудников своего отдела;
        // own-scope — только свои заявки.
        $scope = $this->permissions->can($request->user(), 'leaves.approve');

        if ($scope === false) {
            return back()->withErrors(['field' => __('Нет права согласовывать отпуска')]);
        }

        if ($scope !== PermissionScope::Company) {
            $applicant = $leave->employee;

            if ($scope === PermissionScope::Department) {
                $sameDepartment = $applicant !== null
                    && $me->department_id !== null
                    && $applicant->department_id === $me->department_id;

                if (! $sameDepartment) {
                    return back()->withErrors(['field' => __('Согласование доступно только для сотрудников вашего отдела')]);
                }
            } elseif ($applicant === null || $applicant->id !== $me->id) {
                return back()->withErrors(['field' => __('Согласование доступно только для собственных заявок')]);
            }
        }

        $validated = $request->validate([
            'decision_comment' => ['nullable', 'string', 'max:500'],
        ]);

        $old = $leave->only(['status']);
        $from = $leave->status->value;

        // решение принимается только из pending (единая статусная машина, Этап B)
        try {
            $this->engine->assert(
                WorkflowEngine::SUBJECT_LEAVE,
                $from,
                $decision,
                'По заявке уже принято решение: '.EnumLabels::label('leave_status', $from),
            );
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        $leave->update([
            'status' => $decision,
            'approver_id' => $me->id,
            'decided_at' => now(),
            'decision_comment' => $validated['decision_comment'] ?? null,
        ]);
        $this->engine->record(
            WorkflowEngine::SUBJECT_LEAVE,
            $leave->id,
            $from,
            $decision,
            $decision === 'approved' ? 'approve' : 'reject',
            $request->user(),
            $validated['decision_comment'] ?? null,
        );

        $this->audit->log($request->user(), 'leave.'.$decision, 'leave_request', $leave->id, $old, [
            'status' => $decision,
            'from_date' => $leave->from_date->toDateString(),
            'to_date' => $leave->to_date->toDateString(),
        ]);

        return back()->with('status', $decision === 'approved' ? __('Отпуск согласован') : __('Заявка отклонена'));
    }

    /** Личное расписание сотрудника: свой график + предстоящие отпуска (абзац про сам_просмотр). */
    public function mySchedule(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $schedule = $me->workSchedule;

        $assignments = ScheduleAssignment::query()
            ->with('workSchedule')
            ->where('employee_id', $me->id)
            ->orderByDesc('effective_from')
            ->get();

        $holidays = Holiday::query()->where(fn ($query) => $query->where('company_id', $me->company_id)->orWhereNull('company_id'))
            ->whereYear('date', now()->year)->orderBy('date')->get();

        return view('schedules.mine', compact('schedule', 'assignments', 'holidays'));
    }
}
