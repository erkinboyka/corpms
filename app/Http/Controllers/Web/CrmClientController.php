<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Crm\Services\CrmService;
use App\Http\Controllers\Controller;
use App\Models\CrmClient;
use App\Models\CrmContact;
use App\Models\Employee;
use App\Shared\Enums\CrmClientKind;
use App\Shared\Enums\CrmClientStatus;
use App\Shared\Enums\CrmDealStage;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Клиенты CRM (Этап E2): карточка клиента, контакты, сделки, история.
 *
 * Изоляция: company-скоуп в списках, EnsureTenantIsolation для маршрутных
 * моделей и TenantGuard::ensureReference для owner_id из формы.
 */
class CrmClientController extends Controller
{
    public function __construct(
        protected CrmService $crm,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Список клиентов с поиском и фильтром статуса. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status', CrmClientStatus::Active->value);

        if (in_array($status, ['active', 'archived', 'all'], true) === false) {
            $status = CrmClientStatus::Active->value;
        }

        $clients = CrmClient::query()
            ->where('company_id', $companyId)
            ->with('owner')
            ->withCount(['contacts', 'deals'])
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(static function ($sub) use ($search): void {
                    $sub->where('name', 'like', '%'.$search.'%')
                        ->orWhere('inn', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($status !== 'all', static fn ($query) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('crm.clients.index', [
            'clients' => $clients,
            'search' => $search,
            'status' => $status,
            'metrics' => $this->crm->metrics($companyId),
            'canManage' => $this->permissions->check($request->user(), 'crm.manage'),
            'employees' => $this->employees($companyId),
            'kinds' => CrmClientKind::cases(),
        ]);
    }

    /** Карточка клиента: контакты, сделки, активности. */
    public function show(Request $request, CrmClient $client): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($client, $me);

        $client->load([
            'owner',
            'contacts',
            'deals' => static fn ($query) => $query->with('owner')->orderByDesc('id'),
            'activities' => static fn ($query) => $query->with(['deal', 'owner'])->orderByDesc('id')->limit(20),
        ]);

        return view('crm.clients.show', [
            'client' => $client,
            'metrics' => $this->crm->clientMetrics($client),
            'canManage' => $this->permissions->check($request->user(), 'crm.manage'),
            'canDeals' => $this->permissions->check($request->user(), 'crm.deals.manage'),
            'employees' => $this->employees((int) $me->company_id),
            'kinds' => CrmClientKind::cases(),
            'stages' => CrmDealStage::cases(),
        ]);
    }

    /** Создание клиента. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['name' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $this->validateClient($request);
        $this->guardOwner($validated['owner_id'] ?? null, $me);

        $client = CrmClient::query()->create([
            'company_id' => $me->company_id,
            ...$this->clientAttributes($validated),
            'status' => CrmClientStatus::Active,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'crm.client_created', 'crm_client', $client->id, null, [
            'name' => $client->name,
            'kind' => $client->kind->value,
        ]);

        return redirect()->route('crm.clients.show', $client)->with('status', 'Клиент «'.$client->name.'» создан');
    }

    /** Изменение карточки клиента. */
    public function update(Request $request, CrmClient $client): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($client, $me);

        $validated = $this->validateClient($request);
        $this->guardOwner($validated['owner_id'] ?? null, $me);

        $before = ['name' => $client->name, 'status' => $client->status->value];
        $client->fill($this->clientAttributes($validated))->save();

        $this->audit->log($request->user(), 'crm.client_updated', 'crm_client', $client->id, $before, [
            'name' => $client->name,
            'status' => $client->status->value,
        ]);

        return back()->with('status', 'Клиент «'.$client->name.'» обновлён');
    }

    /** Архивация/возврат клиента (без удаления — история сделок сохраняется). */
    public function toggle(Request $request, CrmClient $client): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($client, $me);

        $client->status = $client->status === CrmClientStatus::Active
            ? CrmClientStatus::Archived
            : CrmClientStatus::Active;
        $client->save();

        $this->audit->log($request->user(), 'crm.client_toggled', 'crm_client', $client->id, null, [
            'status' => $client->status->value,
        ]);

        $verb = $client->status === CrmClientStatus::Active ? 'возвращён в работу' : 'архивирован';

        return back()->with('status', 'Клиент «'.$client->name.'» '.$verb);
    }

    /** Добавление контакта клиента. */
    public function storeContact(Request $request, CrmClient $client): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($client, $me);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ], [], ['first_name' => 'имя']);

        if ($request->boolean('is_primary')) {
            $client->contacts()->update(['is_primary' => false]);
        }

        $contact = $client->contacts()->create([
            'company_id' => $client->company_id,
            ...$validated,
            'is_primary' => $request->boolean('is_primary'),
        ]);

        $this->audit->log($request->user(), 'crm.contact_created', 'crm_contact', $contact->id, null, [
            'client_id' => $client->id,
            'name' => $contact->fullName(),
        ]);

        return back()->with('status', 'Контакт «'.$contact->fullName().'» добавлен');
    }

    /** Удаление контакта клиента. */
    public function destroyContact(Request $request, CrmClient $client, CrmContact $contact): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($client, $me);
        TenantGuard::ensure($contact, $me);

        if ((int) $contact->client_id !== (int) $client->id) {
            abort(404, 'Контакт не относится к клиенту');
        }

        $contact->delete();

        $this->audit->log($request->user(), 'crm.contact_deleted', 'crm_contact', $contact->id, null, [
            'client_id' => $client->id,
        ]);

        return back()->with('status', 'Контакт удалён');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateClient(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(CrmClientKind::class)],
            'inn' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'owner_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'название', 'kind' => 'вид клиента']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function clientAttributes(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'kind' => CrmClientKind::from($validated['kind']),
            'inn' => $validated['inn'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'website' => $validated['website'] ?? null,
            'address' => $validated['address'] ?? null,
            'owner_id' => $validated['owner_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
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
