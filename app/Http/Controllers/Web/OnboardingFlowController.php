<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Onboarding\Services\OnboardingOrchestrator;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LmsCourse;
use App\Models\OnboardingFlow;
use App\Models\Role;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * ONB-01: управление потоками адаптации новых сотрудников.
 * Отдельно от страницы первичной настройки компании (`OnboardingController`).
 */
class OnboardingFlowController extends Controller
{
    public function __construct(
        protected OnboardingOrchestrator $orchestrator,
        protected PermissionService $permissions,
    ) {}

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        $companyId = (int) $me->company_id;

        $flows = OnboardingFlow::query()
            ->where('company_id', $companyId)
            ->with(['employee', 'steps'])
            ->orderByDesc('id')
            ->paginate(30);

        $existingIds = OnboardingFlow::query()->where('company_id', $companyId)->pluck('employee_id')->all();

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->whereNotIn('id', $existingIds)
            ->orderBy('last_name')
            ->get();

        return view('onboarding.flows.index', [
            'flows' => $flows,
            'employees' => $employees,
            'courses' => LmsCourse::query()->where('company_id', $companyId)->orderBy('title')->get(),
            'canManage' => $this->permissions->check($request->user(), 'onboarding.manage'),
        ]);
    }

    public function show(Request $request, OnboardingFlow $flow): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($flow, $me);

        $flow->load(['employee', 'steps', 'grants.role']);

        return view('onboarding.flows.show', [
            'flow' => $flow,
            'canManage' => $this->permissions->check($request->user(), 'onboarding.manage'),
            'steps' => OnboardingOrchestrator::STEPS,
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer'],
            'role_slugs' => ['nullable', 'array'],
            'role_slugs.*' => ['string', 'exists:roles,slug'],
            'task_title' => ['nullable', 'string', 'max:255'],
        ], [], ['employee_id' => __('сотрудник')]);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        TenantGuard::ensureEmployee($employee, $me);

        foreach ($validated['course_ids'] ?? [] as $courseId) {
            TenantGuard::ensureReference('lms_courses', (int) $courseId, $me);
        }

        try {
            $flow = $this->orchestrator->start($employee, $request->user(), $validated);
            $flow = $this->orchestrator->run($flow, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->withInput();
        }

        return redirect()->route('onboarding.flows.show', $flow)
            ->with('status', __('Поток онбординга запущен'));
    }

    public function run(Request $request, OnboardingFlow $flow): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($flow, $me);

        try {
            $this->orchestrator->run($flow, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Шаги онбординга выполнены'));
    }

    public function retry(Request $request, OnboardingFlow $flow, string $step): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($flow, $me);

        if (! in_array($step, OnboardingOrchestrator::STEPS, true)) {
            return back()->withErrors(['status' => __('Неизвестный шаг онбординга')]);
        }

        $options = $request->validate([
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer'],
            'role_slugs' => ['nullable', 'array'],
            'role_slugs.*' => ['string', 'exists:roles,slug'],
            'task_title' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($options['course_ids'] ?? [] as $courseId) {
            TenantGuard::ensureReference('lms_courses', (int) $courseId, $me);
        }

        try {
            $this->orchestrator->retry($flow, $step, $request->user(), $options);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Шаг онбординга повторён'));
    }

    public function revoke(Request $request, OnboardingFlow $flow): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($flow, $me);

        $this->orchestrator->revoke($flow, $request->user());

        return back()->with('status', __('Доступы, выданные потоком, отозваны'));
    }
}
