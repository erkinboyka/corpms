<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Crm\Services\CrmService;
use App\Http\Controllers\Controller;
use App\Models\CrmActivity;
use App\Models\CrmClient;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\Employee;
use App\Shared\Enums\CrmActivityKind;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Активности CRM (Этап E2): звонки, встречи, письма, задачи и заметки.
 *
 * Активность привязывается к сделке, лиду или клиенту; «выполнить» фиксирует
 * completed_at, «вернуть в работу» его снимает. Просроченные считаются по due_at.
 */
class CrmActivityController extends Controller
{
    public function __construct(
        protected CrmService $crm,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Список активностей: сегодня / предстоящие / просроченные / выполненные. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $tab = (string) $request->query('tab', 'today');
        $kind = (string) $request->query('kind');

        if (in_array($tab, ['today', 'upcoming', 'overdue', 'done', 'all'], true) === false) {
            $tab = 'today';
        }

        $query = CrmActivity::query()
            ->where('company_id', $companyId)
            ->with(['deal.client', 'client', 'lead', 'owner'])
            ->when($kind !== '' && in_array($kind, array_column(CrmActivityKind::cases(), 'value'), true), static fn ($q) => $q->where('kind', $kind));

        match ($tab) {
            'today' => $query->pending()->whereDate('due_at', now()->toDateString())->orderBy('due_at'),
            'upcoming' => $query->pending()->where('due_at', '>', now()->endOfDay())->orderBy('due_at'),
            'overdue' => $query->pending()->whereNotNull('due_at')->where('due_at', '<', now())->orderBy('due_at'),
            'done' => $query->whereNotNull('completed_at')->orderByDesc('completed_at'),
            default => $query->orderByDesc('id'),
        };

        return view('crm.activities.index', [
            'activities' => $query->paginate(50)->withQueryString(),
            'tab' => $tab,
            'kind' => $kind,
            'kinds' => CrmActivityKind::cases(),
            'metrics' => $this->crm->metrics($companyId),
            'deals' => $this->openDeals($companyId),
            'clients' => $this->clients($companyId),
            'leads' => $this->openLeads($companyId),
            'employees' => $this->employees($companyId),
            'canManage' => $this->permissions->check($request->user(), 'crm.manage'),
        ]);
    }

    /** Создание активности. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['subject' => __('У сотрудника не указана компания')])->withInput();
        }

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(CrmActivityKind::class)],
            'subject' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'deal_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['kind' => __('вид активности'), 'subject' => __('тема')]);

        foreach (['deal_id' => 'crm_deals', 'client_id' => 'crm_clients', 'lead_id' => 'crm_leads', 'owner_id' => 'employees'] as $field => $table) {
            $value = $validated[$field] ?? null;

            if ($value !== null && $value !== '') {
                TenantGuard::ensureReference($table, (int) $value, $me);
            }
        }

        $activity = CrmActivity::query()->create([
            'company_id' => $me->company_id,
            'kind' => CrmActivityKind::from($validated['kind']),
            'subject' => $validated['subject'],
            'due_at' => $validated['due_at'] ?? null,
            'deal_id' => $validated['deal_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'lead_id' => $validated['lead_id'] ?? null,
            'owner_id' => $validated['owner_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'crm.activity_created', 'crm_activity', $activity->id, null, [
            'subject' => $activity->subject,
            'kind' => $activity->kind->value,
        ]);

        return back()->with('status', __('Активность «').$activity->subject.__('» добавлена'));
    }

    /** Отметить выполненной. */
    public function complete(Request $request, CrmActivity $activity): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($activity, $me);

        $this->crm->completeActivity($activity);

        $this->audit->log($request->user(), 'crm.activity_completed', 'crm_activity', $activity->id, null, [
            'subject' => $activity->subject,
        ]);

        return back()->with('status', __('Активность «').$activity->subject.__('» выполнена'));
    }

    /** Вернуть в работу. */
    public function reopen(Request $request, CrmActivity $activity): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($activity, $me);

        $this->crm->reopenActivity($activity);

        $this->audit->log($request->user(), 'crm.activity_reopened', 'crm_activity', $activity->id, null, [
            'subject' => $activity->subject,
        ]);

        return back()->with('status', __('Активность «').$activity->subject.__('» возвращена в работу'));
    }

    /** @return Collection<int, CrmDeal> */
    private function openDeals(int $companyId): Collection
    {
        return CrmDeal::query()
            ->where('company_id', $companyId)
            ->open()
            ->with('client')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /** @return Collection<int, CrmClient> */
    private function clients(int $companyId): Collection
    {
        return CrmClient::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->limit(200)
            ->get();
    }

    /** @return Collection<int, CrmLead> */
    private function openLeads(int $companyId): Collection
    {
        return CrmLead::query()
            ->where('company_id', $companyId)
            ->open()
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /** @return Collection<int, Employee> */
    private function employees(int $companyId): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'middle_name']);
    }
}
