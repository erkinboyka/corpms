<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\DocumentWorkflow;
use App\Models\DocumentWorkflowState;
use App\Models\DocumentWorkflowStep;
use App\Models\Employee;
use App\Models\Role;
use App\Shared\Enums\WorkflowAction;
use App\Shared\Enums\WorkflowActorType;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Маршруты согласования документов (workflows, ТЗ): просмотр и создание
 * линейных маршрутов (последовательности шагов).
 */
class WorkflowController extends Controller
{
    public const MAX_STEPS = 8;

    public function __construct(
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $me = app(PermissionService::class)->employee($request->user());
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }

        // межтенантная изоляция (S2): маршруты согласования своей компании
        $workflows = DocumentWorkflow::query()
            ->with('steps.role')
            ->withCount('steps')
            ->where('company_id', $me->company_id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // документы в каждом маршруте
        $inWorkflow = [];
        foreach ($workflows as $workflow) {
            $inWorkflow[$workflow->id] = DocumentWorkflowState::query()
                ->where('workflow_id', $workflow->id)
                ->count();
        }

        return view('documents.workflows', [
            'workflows' => $workflows,
            'inWorkflow' => $inWorkflow,
            'roles' => Role::query()->orderBy('id')->get(['id', 'name']),
            'employees' => Employee::query()
                ->where('company_id', $me->company_id)
                ->where('status', 'active')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name']),
            'actorTypes' => WorkflowActorType::cases(),
            'actions' => WorkflowAction::cases(),
            'maxSteps' => self::MAX_STEPS,
        ]);
    }

    /**
     * Создание линейного маршрута: название + последовательность шагов.
     * Шаги передаются массивами steps[][name], steps[][actor_type] и т.д.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $me = app(PermissionService::class)->employee($user);
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'applicable_categories' => ['nullable', 'string', 'max:200'],
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*.name' => ['required', 'string', 'max:150'],
            'steps.*.actor_type' => ['required', 'in:role,department,employee,author,ceo'],
            'steps.*.role_id' => ['nullable', 'exists:roles,id'],
            'steps.*.employee_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where('company_id', $me->company_id),
            ],
            'steps.*.required_action' => ['required', 'in:approve,review,sign'],
            'steps.*.is_signature_step' => ['nullable', 'boolean'],
        ]);

        // BL-21: шаг «сотрудник» без назначенного сотрудника не должен
        // молча резолвиться на автора документа.
        foreach ($validated['steps'] as $index => $step) {
            if ($step['actor_type'] === 'employee' && empty($step['employee_id'])) {
                throw ValidationException::withMessages([
                    'steps.'.$index.'.employee_id' => __('Для шага «сотрудник» выберите сотрудника'),
                ]);
            }
        }

        $categories = array_values(array_filter(array_map(
            'trim',
            /* категории вводят через пробел (как на других страницах) или через запятую */
            preg_split('/[\s,]+/', $validated['applicable_categories'] ?? ''),
        )));

        $workflow = DB::transaction(function () use ($validated, $categories, $me) {
            $workflow = DocumentWorkflow::create([
                'company_id' => $me->company_id,
                'name' => $validated['name'],
                'applicable_categories' => $categories === [] ? null : $categories,
                'is_active' => true,
            ]);

            foreach (array_values($validated['steps']) as $index => $step) {
                $actorType = WorkflowActorType::from($step['actor_type']);

                DocumentWorkflowStep::create([
                    'workflow_id' => $workflow->id,
                    'step_order' => $index + 1,
                    'name' => $step['name'],
                    'actor_type' => $actorType,
                    'role_id' => $actorType === WorkflowActorType::Role ? (int) ($step['role_id'] ?? 0) ?: null : null,
                    'employee_id' => $actorType === WorkflowActorType::Employee ? (int) $step['employee_id'] : null,
                    'required_action' => $step['required_action'],
                    'is_signature_step' => (bool) ($step['is_signature_step'] ?? false),
                    'is_terminal' => $index === count($validated['steps']) - 1,
                ]);
            }

            return $workflow;
        });

        $this->audit->log($user, 'workflow.created', 'document_workflow', $workflow->id, null, [
            'name' => $workflow->name,
            'steps' => count($validated['steps']),
        ]);

        return redirect()->route('workflows.index')->with('status', __('Маршрут «').$workflow->name.__('» создан'));
    }
}
