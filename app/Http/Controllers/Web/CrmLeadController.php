<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Crm\Services\CrmService;
use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\Employee;
use App\Shared\Enums\CrmLeadSource;
use App\Shared\Enums\CrmLeadStatus;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;

/**
 * Лиды CRM (Этап E2): входящий интерес до сделки.
 *
 * Конверсия лида создаёт клиента (если его нет) и сделку в стадии «new»,
 * лид переходит в статус converted — повторно сконвертировать нельзя.
 */
class CrmLeadController extends Controller
{
    public function __construct(
        protected CrmService $crm,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Список лидов с фильтрами по статусу, источнику и поиску. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status', 'open');
        $source = (string) $request->query('source');

        $allowedStatuses = ['open', 'new', 'qualified', 'converted', 'lost', 'all'];

        if (in_array($status, $allowedStatuses, true) === false) {
            $status = 'open';
        }

        $leads = CrmLead::query()
            ->where('company_id', $companyId)
            ->with(['client', 'owner'])
            ->when($status === 'open', static fn ($query) => $query->open())
            ->when($status !== 'open' && $status !== 'all', static fn ($query) => $query->where('status', $status))
            ->when($source !== '' && in_array($source, array_column(CrmLeadSource::cases(), 'value'), true), static fn ($query) => $query->where('source', $source))
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(static function ($sub) use ($search): void {
                    $sub->where('title', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $counts = CrmLead::query()
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('crm.leads.index', [
            'leads' => $leads,
            'search' => $search,
            'status' => $status,
            'source' => $source,
            'metrics' => $this->crm->metrics($companyId),
            'counts' => $counts,
            'sources' => CrmLeadSource::cases(),
            'canManage' => $this->permissions->check($request->user(), 'crm.manage'),
            'canDeals' => $this->permissions->check($request->user(), 'crm.deals.manage'),
            'employees' => $this->employees($companyId),
        ]);
    }

    /** Создание лида. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['title' => __('У сотрудника не указана компания')])->withInput();
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'source' => ['required', Rule::enum(CrmLeadSource::class)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'owner_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['title' => __('название'), 'source' => __('источник')]);

        $this->guardOwner($validated['owner_id'] ?? null, $me);

        $lead = CrmLead::query()->create([
            'company_id' => $me->company_id,
            'title' => $validated['title'],
            'source' => CrmLeadSource::from($validated['source']),
            'contact_name' => $validated['contact_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'status' => CrmLeadStatus::New,
            'estimated_amount' => $validated['estimated_amount'] ?? 0,
            'owner_id' => $validated['owner_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'crm.lead_created', 'crm_lead', $lead->id, null, [
            'title' => $lead->title,
            'source' => $lead->source->value,
        ]);

        return redirect()->route('crm.leads.index')->with('status', __('Лид «').$lead->title.__('» создан'));
    }

    /** Квалификация лида (new → qualified). */
    public function qualify(Request $request, CrmLead $lead): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($lead, $me);

        if ($lead->status !== CrmLeadStatus::New) {
            return back()->withErrors(['status' => __('Квалифицировать можно только новый лид')]);
        }

        $lead->status = CrmLeadStatus::Qualified;
        $lead->save();

        $this->audit->log($request->user(), 'crm.lead_qualified', 'crm_lead', $lead->id, null, [
            'status' => $lead->status->value,
        ]);

        return back()->with('status', __('Лид «').$lead->title.__('» квалифицирован'));
    }

    /** Потеря лида (new/qualified → lost). */
    public function lose(Request $request, CrmLead $lead): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($lead, $me);

        if ($lead->status->isOpen() === false) {
            return back()->withErrors(['status' => __('Лид уже закрыт')]);
        }

        $lead->status = CrmLeadStatus::Lost;
        $lead->save();

        $this->audit->log($request->user(), 'crm.lead_lost', 'crm_lead', $lead->id, null, [
            'status' => $lead->status->value,
        ]);

        return back()->with('status', __('Лид «').$lead->title.__('» отмечен как потерянный'));
    }

    /** Конверсия лида в клиента и сделку. */
    public function convert(Request $request, CrmLead $lead): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($lead, $me);

        try {
            $deal = $this->crm->convertLead($lead, $me->id);
        } catch (LogicException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $this->audit->log($request->user(), 'crm.lead_converted', 'crm_lead', $lead->id, null, [
            'deal_id' => $deal->id,
            'client_id' => $deal->client_id,
        ]);

        return redirect()
            ->route('crm.deals.index')
            ->with('status', __('Лид «').$lead->title.__('» сконвертирован: сделка №').$deal->id);
    }

    private function guardOwner(mixed $ownerId, Employee $me): void
    {
        if ($ownerId !== null && $ownerId !== '') {
            TenantGuard::ensureReference('employees', (int) $ownerId, $me);
        }
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
