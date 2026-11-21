<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Crm\Services\CrmService;
use App\Http\Controllers\Controller;
use App\Models\CrmClient;
use App\Models\CrmDeal;
use App\Models\Employee;
use App\Shared\Enums\CrmDealStage;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Воронка сделок CRM (Этап E2): канбан по стадиям и метрики.
 *
 * Перемещение карточки — отдельный POST со стадией; закрытые стадии
 * (won/lost) фиксируют closed_at и вероятность через CrmService.
 */
class CrmDealController extends Controller
{
    public function __construct(
        protected CrmService $crm,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Канбан воронки: сделки по стадиям + метрики. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $search = trim((string) $request->query('q'));
        $ownerId = $request->query('owner');

        $board = $this->crm->board(
            $companyId,
            is_numeric($ownerId) ? (int) $ownerId : null,
            $search !== '' ? $search : null,
        );

        return view('crm.deals.index', [
            'board' => $board,
            'stages' => CrmDealStage::cases(),
            'search' => $search,
            'ownerFilter' => $ownerId,
            'metrics' => $this->crm->metrics($companyId),
            'clients' => $this->clients($companyId),
            'employees' => $this->employees($companyId),
            'canManage' => $this->permissions->check($request->user(), 'crm.deals.manage'),
        ]);
    }

    /** Создание сделки. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['title' => __('У сотрудника не указана компания')])->withInput();
        }

        $validated = $this->validateDeal($request);
        TenantGuard::ensureReference('crm_clients', (int) $validated['client_id'], $me);
        $this->guardOwner($validated['owner_id'] ?? null, $me);

        $stage = CrmDealStage::from($validated['stage'] ?? CrmDealStage::New->value);

        $deal = CrmDeal::query()->create([
            'company_id' => $me->company_id,
            'client_id' => (int) $validated['client_id'],
            'title' => $validated['title'],
            'amount' => $validated['amount'] ?? 0,
            'stage' => $stage,
            'probability' => $validated['probability'] ?? $stage->defaultProbability(),
            'expected_close_date' => $validated['expected_close_date'] ?? null,
            'closed_at' => $stage->isClosed() ? now()->toDateString() : null,
            'owner_id' => $validated['owner_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'crm.deal_created', 'crm_deal', $deal->id, null, [
            'title' => $deal->title,
            'amount' => (string) $deal->amount,
            'stage' => $stage->value,
        ]);

        return redirect()->route('crm.deals.index')->with('status', __('Сделка «').$deal->title.__('» создана'));
    }

    /** Изменение условий сделки (сумма, срок, ответственный). */
    public function update(Request $request, CrmDeal $deal): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($deal, $me);

        $validated = $this->validateDeal($request);
        TenantGuard::ensureReference('crm_clients', (int) $validated['client_id'], $me);
        $this->guardOwner($validated['owner_id'] ?? null, $me);

        $before = ['title' => $deal->title, 'amount' => (string) $deal->amount];

        $deal->fill([
            'client_id' => (int) $validated['client_id'],
            'title' => $validated['title'],
            'amount' => $validated['amount'] ?? 0,
            'probability' => $validated['probability'] ?? $deal->probability,
            'expected_close_date' => $validated['expected_close_date'] ?? null,
            'owner_id' => $validated['owner_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ])->save();

        $this->audit->log($request->user(), 'crm.deal_updated', 'crm_deal', $deal->id, $before, [
            'title' => $deal->title,
            'amount' => (string) $deal->amount,
        ]);

        return back()->with('status', __('Сделка «').$deal->title.__('» обновлена'));
    }

    /** Перемещение по воронке (won/lost закрывают сделку). */
    public function move(Request $request, CrmDeal $deal): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($deal, $me);

        $validated = $request->validate([
            'stage' => ['required', Rule::enum(CrmDealStage::class)],
        ], [], ['stage' => __('стадия')]);

        $from = $deal->stage;
        $this->crm->moveDeal($deal, CrmDealStage::from($validated['stage']));

        $this->audit->log($request->user(), 'crm.deal_moved', 'crm_deal', $deal->id, [
            'stage' => $from->value,
        ], [
            'stage' => $deal->stage->value,
            'amount' => (string) $deal->amount,
        ]);

        return back()->with('status', __('Сделка «').$deal->title.__('»: стадия «').$this->stageLabel($deal->stage).'»');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDeal(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'stage' => ['nullable', Rule::enum(CrmDealStage::class)],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['title' => __('название'), 'client_id' => __('клиент'), 'amount' => __('сумма')]);
    }

    private function stageLabel(CrmDealStage $stage): string
    {
        return match ($stage) {
            CrmDealStage::New => __('Новая'),
            CrmDealStage::Qualification => __('Квалификация'),
            CrmDealStage::Proposal => __('Предложение'),
            CrmDealStage::Negotiation => __('Переговоры'),
            CrmDealStage::Won => __('Выиграна'),
            CrmDealStage::Lost => __('Проиграна'),
        };
    }

    private function guardOwner(mixed $ownerId, Employee $me): void
    {
        if ($ownerId !== null && $ownerId !== '') {
            TenantGuard::ensureReference('employees', (int) $ownerId, $me);
        }
    }

    /** @return Collection<int, CrmClient> */
    private function clients(int $companyId): Collection
    {
        return CrmClient::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->limit(300)
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
