<?php

declare(strict_types=1);

namespace App\Domains\Crm\Services;

use App\Models\CrmActivity;
use App\Models\CrmClient;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Shared\Enums\CrmClientKind;
use App\Shared\Enums\CrmClientStatus;
use App\Shared\Enums\CrmDealStage;
use App\Shared\Enums\CrmLeadStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * CRM-движок (Этап E2): воронка сделок, конверсия лидов, активности.
 *
 * Правила:
 *   - стадии воронки закрывают сделку (won/lost) и проставляют closed_at;
 *   - конверсия лида атомарна: клиент (при необходимости) + сделка + статус лида;
 *   - все запросы ограничены компанией; вызывающий код проверяет права.
 */
class CrmService
{
    /**
     * Сделки компании, сгруппированные по стадиям воронки.
     *
     * @return array<string, Collection<int, CrmDeal>>
     */
    public function board(int $companyId, ?int $ownerId = null, ?string $search = null): array
    {
        $deals = CrmDeal::query()
            ->where('company_id', $companyId)
            ->with(['client', 'owner'])
            ->when($ownerId !== null, static fn ($query) => $query->where('owner_id', $ownerId))
            ->when($search !== null && $search !== '', static function ($query) use ($search): void {
                $query->where(static function ($sub) use ($search): void {
                    $sub->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('client', static fn ($client) => $client->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->orderByDesc('amount')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $board = [];

        foreach (CrmDealStage::cases() as $stage) {
            $board[$stage->value] = $deals
                ->filter(static fn (CrmDeal $deal): bool => $deal->stage === $stage)
                ->values();
        }

        return $board;
    }

    /**
     * Метрики CRM для шапки страниц.
     *
     * @return array<string, int|float>
     */
    public function metrics(int $companyId): array
    {
        $openDeals = CrmDeal::query()->where('company_id', $companyId)->open();
        $wonThisMonth = CrmDeal::query()
            ->where('company_id', $companyId)
            ->where('stage', CrmDealStage::Won->value)
            ->whereBetween('closed_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        return [
            'clients' => CrmClient::query()->where('company_id', $companyId)->count(),
            'clients_active' => CrmClient::query()->where('company_id', $companyId)->active()->count(),
            'leads_open' => CrmLead::query()->where('company_id', $companyId)->open()->count(),
            'deals_open' => (clone $openDeals)->count(),
            'deals_amount' => (float) (clone $openDeals)->sum('amount'),
            'won_month' => (float) (clone $wonThisMonth)->sum('amount'),
            'activities_pending' => CrmActivity::query()->where('company_id', $companyId)->pending()->count(),
            'activities_overdue' => CrmActivity::query()
                ->where('company_id', $companyId)
                ->pending()
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count(),
        ];
    }

    /**
     * Конверсия лида: клиент (если ещё нет) + сделка + статус «converted».
     *
     * @throws LogicException если лид уже закрыт
     */
    public function convertLead(CrmLead $lead, int $actorEmployeeId): CrmDeal
    {
        return DB::transaction(function () use ($lead, $actorEmployeeId): CrmDeal {
            $lead = CrmLead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($lead->status->isOpen() === false) {
                throw new LogicException('Лид уже закрыт: конверсия невозможна');
            }

            $client = $lead->client;

            if ($client === null) {
                $client = CrmClient::query()->create([
                    'company_id' => $lead->company_id,
                    'name' => $lead->title,
                    'kind' => CrmClientKind::Company,
                    'phone' => $lead->phone,
                    'email' => $lead->email,
                    'status' => CrmClientStatus::Active,
                    'owner_id' => $lead->owner_id,
                    'notes' => $lead->notes,
                    'created_by' => $lead->created_by,
                ]);
            }

            $deal = CrmDeal::query()->create([
                'company_id' => $lead->company_id,
                'client_id' => $client->id,
                'lead_id' => $lead->id,
                'title' => $lead->title,
                'amount' => $lead->estimated_amount,
                'stage' => CrmDealStage::New,
                'probability' => CrmDealStage::New->defaultProbability(),
                'expected_close_date' => now()->addMonth()->toDateString(),
                'owner_id' => $lead->owner_id ?? $actorEmployeeId,
                'created_by' => $lead->created_by,
            ]);

            $lead->status = CrmLeadStatus::Converted;
            $lead->client_id = $client->id;
            $lead->save();

            return $deal->setRelation('client', $client);
        });
    }

    /**
     * Перемещение сделки по воронке: закрытые стадии фиксируют дату и вероятность.
     */
    public function moveDeal(CrmDeal $deal, CrmDealStage $stage): CrmDeal
    {
        if ($deal->stage === $stage) {
            return $deal;
        }

        $deal->stage = $stage;
        $deal->closed_at = $stage->isClosed() ? now()->toDateString() : null;

        // Ручную вероятность сохраняем, кроме перехода в закрытую стадию
        // и «сброса» закрытой сделки обратно в работу.
        if ($stage->isClosed() || $deal->probability === 0 || $deal->probability === 100) {
            $deal->probability = $stage->defaultProbability();
        }

        $deal->save();

        return $deal;
    }

    public function completeActivity(CrmActivity $activity): CrmActivity
    {
        if ($activity->completed_at === null) {
            $activity->completed_at = now();
            $activity->save();
        }

        return $activity;
    }

    public function reopenActivity(CrmActivity $activity): CrmActivity
    {
        if ($activity->completed_at !== null) {
            $activity->completed_at = null;
            $activity->save();
        }

        return $activity;
    }

    /**
     * Показатели клиента для карточки.
     *
     * @return array<string, int|float>
     */
    public function clientMetrics(CrmClient $client): array
    {
        return [
            'deals_open' => $client->deals()->open()->count(),
            'amount_open' => (float) $client->deals()->open()->sum('amount'),
            'amount_won' => (float) $client->deals()->where('stage', CrmDealStage::Won->value)->sum('amount'),
            'activities_pending' => $client->activities()->pending()->count(),
        ];
    }
}
