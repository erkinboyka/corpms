<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Журнал действий (п.56 / п.64 ТЗ).
 *
 * Межтенантная изоляция (S2): журнал — данные компании, поэтому выборка,
 * подсказки фильтров и список пользователей скоупятся компанией сотрудника.
 * Системные события без пользователя (user_id IS NULL) привязываются к
 * компании через объект-документ; иные глобальные события показываются всем.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $q = $this->scopedLogs(AuditLog::query()->with('user'), $me->company_id);

        $action = request('action');
        if ($action !== null && $action !== '') {
            $q->where('action', 'like', $action.'%');
        }

        $userId = request('user_id');
        if ($userId !== null && $userId !== '') {
            $q->where('user_id', (int) $userId);
        }

        if (filled(request('from'))) {
            $from = Carbon::parse((string) request('from'))->startOfDay();
            $q->where('created_at', '>=', $from);
        }
        if (filled(request('to'))) {
            $to = Carbon::parse((string) request('to'))->endOfDay();
            $q->where('created_at', '<=', $to);
        }

        $logs = $q->orderByDesc('id')->paginate(50)->withQueryString();

        // подсказки фильтров — только по записям своей компании
        $users = $this->scopedUsers($me->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $actions = $this->scopedLogs(AuditLog::query(), $me->company_id)
            ->selectRaw('distinct action')
            ->orderBy('action')
            ->limit(50)
            ->pluck('action');

        return view('audit.index', compact('logs', 'users', 'actions', 'action', 'userId'));
    }

    /**
     * Скоуп журнала: автор из своей компании либо системное событие,
     * привязанное к объекту своей компании.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    private function scopedLogs(Builder $query, ?int $companyId): Builder
    {
        if ($companyId === null) {
            // Учётка без компании не видит журнал.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $sub) use ($companyId) {
            $sub->whereHas('user.employee', fn (Builder $employee) => $employee->where('company_id', $companyId))
                ->orWhere(function (Builder $system) use ($companyId) {
                    $system->whereNull('user_id')
                        ->where(function (Builder $object) use ($companyId) {
                            $object->where('object_type', '!=', 'Document')
                                ->orWhere(function (Builder $document) use ($companyId) {
                                    $document->where('object_type', 'Document')
                                        ->whereIn(
                                            'object_id',
                                            fn ($ids) => $ids->select('id')->from('documents')->where('company_id', $companyId)
                                        );
                                });
                        });
                });
        });
    }

    /** Пользователи для подсказки фильтра — только сотрудники своей компании. */
    private function scopedUsers(?int $companyId): Builder
    {
        if ($companyId === null) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()->whereHas('employee', fn (Builder $employee) => $employee->where('company_id', $companyId));
    }
}
