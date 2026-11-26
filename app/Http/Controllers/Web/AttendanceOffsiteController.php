<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Attendance\Services\AttendanceOffsiteService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceOffsiteRequest;
use App\Models\Employee;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * Заявки на удалённую работу (п. 27–29 ТЗ).
 * Все решения принимаются сервисом; контроллер только проверяет права и tenant.
 */
class AttendanceOffsiteController extends Controller
{
    public function __construct(
        protected AttendanceOffsiteService $offsite,
        protected PermissionService $permissions,
    ) {}

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'attendance.offsite.view');

        $query = AttendanceOffsiteRequest::query()
            ->with(['employee', 'requester', 'decider'])
            ->where('company_id', $me->company_id)
            ->orderByDesc('id');

        if ($scope === PermissionScope::Own) {
            $query->where(fn ($q) => $q->where('employee_id', $me->id)->orWhere('requested_by', $me->id));
        } elseif ($scope === PermissionScope::Department && $me->department_id !== null) {
            $deptIds = Employee::query()
                ->where('company_id', $me->company_id)
                ->where('department_id', $me->department_id)
                ->pluck('id');
            $query->where(fn ($q) => $q->whereIn('employee_id', $deptIds)->orWhere('requested_by', $me->id));
        }

        return view('attendance.offsite', [
            'me' => $me,
            'requests' => $query->limit(100)->get(),
            'canDecide' => $this->permissions->can($request->user(), 'attendance.offsite.approve') !== false,
            'employees' => Employee::query()
                ->where('company_id', $me->company_id)
                ->where('status', 'active')
                ->orderBy('last_name')
                ->limit(200)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'employee_id' => ['nullable', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $target = $me;
        if (($validated['employee_id'] ?? null) !== null && (int) $validated['employee_id'] !== (int) $me->id) {
            $target = Employee::query()->findOrFail($validated['employee_id']);
            TenantGuard::ensureEmployee($target, $me);
            if (! $this->permissions->canAccessEmployee($request->user(), 'attendance.offsite.request', $target)) {
                abort(403, __('Нет прав на заявку за этого сотрудника'));
            }
        }

        try {
            $offsite = $this->offsite->request($me, $target, $validated['date'], $validated['reason']);
        } catch (LogicException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('attendance.offsite')
            ->with('status', __('Заявка №').$offsite->id.' отправлена руководителю. Присутствие не учитывается до согласования.');
    }

    public function approve(Request $request, AttendanceOffsiteRequest $offsite): RedirectResponse
    {
        return $this->decide($request, $offsite, true);
    }

    public function reject(Request $request, AttendanceOffsiteRequest $offsite): RedirectResponse
    {
        $validated = $request->validate([
            'decision_comment' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->decide($request, $offsite, false, $validated['decision_comment']);
    }

    public function cancel(Request $request, AttendanceOffsiteRequest $offsite): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($offsite, $me);

        try {
            $this->offsite->cancel($me, $offsite);
        } catch (LogicException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', __('Заявка отозвана'));
    }

    protected function decide(Request $request, AttendanceOffsiteRequest $offsite, bool $approve, ?string $comment = null): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($offsite, $me);

        $employee = $offsite->employee;
        $isManager = $employee->manager_id !== null && (int) $employee->manager_id === (int) $me->id;
        $canApproveScope = $this->permissions->canAccessEmployee($request->user(), 'attendance.offsite.approve', $employee);

        if (! $isManager && ! $canApproveScope) {
            abort(403, __('Нет прав на согласование удалённой работы'));
        }

        try {
            $this->offsite->decide($me, $offsite, $approve, $comment ?? __('Согласовано'), $canApproveScope);
        } catch (LogicException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $approve
            ? __('Удалённая работа согласована: день учтён как PRESENT без face-проверки')
            : __('Заявка отклонена, табель не изменён'));
    }
}
