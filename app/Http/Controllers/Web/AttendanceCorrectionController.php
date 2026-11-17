<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Attendance\Services\AttendanceCorrectionService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use LogicException;

/**
 * Корректировки посещаемости (п.28 ТЗ).
 */
class AttendanceCorrectionController extends Controller
{
    public function __construct(
        protected AttendanceCorrectionService $corrections,
        protected PermissionService $permissions,
    ) {}

    /** Список заявок: свои + в scope согласующего. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'attendance.correct');

        $query = AttendanceCorrection::query()
            ->with(['employee', 'requestedBy', 'decidedBy'])
            // межтенантная изоляция: корректировки только сотрудников своей компании
            ->whereHas('employee', fn ($q) => $q->where('company_id', $me->company_id))
            ->orderByDesc('id');

        if ($scope === PermissionScope::Own) {
            $query->where(fn ($q) => $q->where('employee_id', $me->id)->orWhere('requested_by', $me->id));
        } elseif ($scope === PermissionScope::Department) {
            $deptIds = Employee::query()->where('department_id', $me->department_id)->pluck('id');
            $query->where(fn ($q) => $q->whereIn('employee_id', $deptIds)->orWhere('requested_by', $me->id));
        }

        $correctionList = $query->limit(100)->get();

        return view('attendance.corrections', [
            'corrections' => $correctionList,
            'canDecide' => $this->permissions->check($request->user(), 'attendance.corrections.approve') !== false,
        ]);
    }

    /** Подача заявки (себе или подчинённому в scope attendance.correct). */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'employee_id' => ['nullable', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'field' => ['required', 'in:clock_in_at,clock_out_at'],
            'old_value' => ['nullable', 'date'],
            'new_value' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $target = $me;
        if (($validated['employee_id'] ?? null) !== null && (int) $validated['employee_id'] !== (int) $me->id) {
            $target = Employee::query()->findOrFail($validated['employee_id']);
            // тенант-проверка до scope: чужая компания — 403 независимо от scope
            \App\Support\TenantGuard::ensureEmployee($target, $me);
            if ($this->canTouch($request->user(), $target, 'attendance.correct') === false) {
                abort(403, 'Нет прав на корректировку этого сотрудника');
            }
        }

        try {
            $correction = $this->corrections->request(
                $me,
                $target,
                $validated['field'],
                $validated['date'],
                isset($validated['old_value']) ? Carbon::parse($validated['old_value']) : null,
                Carbon::parse($validated['new_value']),
                $validated['reason'],
            );
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('attendance.corrections')
            ->with('status', 'Заявка №'.$correction->id.' отправлена на согласование');
    }

    public function approve(Request $request, AttendanceCorrection $correction): RedirectResponse
    {
        return $this->decide($request, $correction, true);
    }

    public function reject(Request $request, AttendanceCorrection $correction): RedirectResponse
    {
        $validated = $request->validate([
            'decision_comment' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->decide($request, $correction, false, $validated['decision_comment']);
    }

    protected function decide(Request $request, AttendanceCorrection $correction, bool $approve, ?string $comment = null): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($this->canTouch($request->user(), $correction->employee, 'attendance.corrections.approve') === false) {
            abort(403, 'Нет прав на согласование');
        }

        try {
            $this->corrections->decide($me, $correction, $approve, $comment ?? 'Согласовано');
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', $approve ? 'Корректировка согласована, табель обновлён' : 'Корректировка отклонена');
    }

    /** Scope-проверка права на конкретного сотрудника. */
    protected function canTouch(User $user, Employee $target, string $permission): bool
    {
        $scope = $this->permissions->can($user, $permission);

        return $scope !== false && $this->scopeAllows($scope, $user->employee, $target);
    }

    protected function scopeAllows(PermissionScope $scope, Employee $me, Employee $target): bool
    {
        return match ($scope) {
            // company-scope = «вся моя компания»: цель из другой компании — запрет
            PermissionScope::Company => (int) $target->company_id === (int) $me->company_id,
            // department-scope: та же компания И заполненные одинаковые отделы
            // (иначе `null === null` пускал бы цель без отдела из любой компании)
            PermissionScope::Department => (int) $target->company_id === (int) $me->company_id
                && $me->department_id !== null
                && $target->department_id !== null
                && (int) $target->department_id === (int) $me->department_id,
            default => (int) $target->id === (int) $me->id,
        };
    }
}
