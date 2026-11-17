<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Biometric\Services\RfidService;
use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Employee;
use App\Models\RfidCard;
use App\Shared\Enums\RfidCardStatus;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use LogicException;

/**
 * RFID-карты сотрудников и журнал проходит ридеров (п.72 ТЗ).
 */
class RfidController extends Controller
{
    public function __construct(
        protected RfidService $rfid,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $me = $this->permissions->employee($user);
        if ($me === null) {
            abort(403, 'Нет карточки сотрудника');
        }

        $scope = $this->permissions->can($user, 'biometry.rfid.view');

        $cards = RfidCard::query()
            ->with(['employee', 'issuedBy'])
            // межтенантная изоляция (S2): карты только сотрудников своей компании
            ->whereHas('employee', fn ($q) => $q->where('company_id', $me->company_id))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // ограничение области: department видит свой отдел, own — свое
        $deptIds = collect();
        if ($scope === PermissionScope::Own) {
            $cards = $cards->filter(fn (RfidCard $card) => (int) $card->employee_id === (int) $me->id);
        } elseif ($scope === PermissionScope::Department) {
            $deptIds = Employee::query()
                ->where('company_id', $me->company_id)
                ->where('department_id', $me->department_id)
                ->pluck('id');
            $cards = $cards->filter(fn (RfidCard $card) => $deptIds->contains((int) $card->employee_id));
        }

        $ownCompanyDeviceIds = BiometricDevice::query()->where('company_id', $me->company_id)->pluck('id');

        $events = BiometricEvent::query()
            ->where('template_type', 'rfid')
            ->with(['device', 'employee'])
            // события ридеров: только своей компании (устройство либо сотрудник)
            ->where(fn ($q) => $q->whereIn('device_id', $ownCompanyDeviceIds)
                ->orWhereHas('employee', fn ($e) => $e->where('company_id', $me->company_id)))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(function (BiometricEvent $event) use ($scope, $me, $deptIds) {
                if ($scope === PermissionScope::Own) {
                    return (int) $event->employee_id === (int) $me->id;
                }
                if ($scope === PermissionScope::Department) {
                    return $event->employee_id !== null && $deptIds->contains((int) $event->employee_id);
                }

                return true;
            });

        // сотрудники для выдачи карт (в своей компании в scope manage)
        $manageScope = $this->permissions->can($user, 'biometry.rfid.manage');
        $employees = Employee::query()
            ->where('company_id', $me->company_id)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get();
        if ($manageScope === PermissionScope::Own) {
            $employees = $employees->filter(fn (Employee $e) => (int) $e->id === (int) $me->id);
        } elseif ($manageScope === PermissionScope::Department) {
            $employees = $employees->filter(fn (Employee $e) => (int) $e->department_id === (int) $me->department_id);
        }

        return view('biometrics.rfid', [
            'cards' => $cards->values(),
            'events' => $events->values(),
            'employees' => $employees->values(),
            'canManage' => $this->permissions->check($user, 'biometry.rfid.manage'),
        ]);
    }

    /** Выдача/привязка карты сотруднику. */
    public function issue(Request $request): RedirectResponse
    {
        $me = $this->permissions->employee($request->user());
        \assert($me !== null);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'card_uid' => ['required', 'string', 'max:64', 'unique:rfid_cards,card_uid'],
            'label' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $owner = Employee::query()->findOrFail($validated['employee_id']);
        if ((int) $owner->company_id !== (int) $me->company_id) {
            abort(403, 'Сотрудник другой компании');
        }

        try {
            $card = $this->rfid->issueCard(
                $me,
                $owner,
                $validated['card_uid'],
                $validated['label'] ?? null,
                isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
            );
        } catch (LogicException $e) {
            return back()->withErrors(['card_uid' => $e->getMessage()])->withInput();
        }

        $this->audit->log($request->user(), 'rfid.card.issued', 'rfid_card', $card->id, null, [
            'card_uid' => $card->card_uid,
            'employee_id' => $owner->id,
        ]);

        return redirect()->route('rfid.index')
            ->with('status', 'Карта ' . $card->card_uid . ' выдана: ' . $owner->last_name);
    }

    /** Отзыв карты (disabled, журнал сохраняется). */
    public function revoke(RfidCard $card): RedirectResponse
    {
        $me = $this->permissions->employee(auth()->user());
        \assert($me !== null);

        if ((int) $card->employee->company_id !== (int) $me->company_id) {
            abort(403, 'Карта другой компании');
        }

        if ($card->status === RfidCardStatus::Disabled) {
            return back()->withErrors(['card_uid' => 'Карта уже отозвана']);
        }

        $this->rfid->revokeCard($card);

        $this->audit->log(auth()->user(), 'rfid.card.revoked', 'rfid_card', $card->id, null, [
            'card_uid' => $card->card_uid,
        ]);

        return back()->with('status', 'Карта ' . $card->card_uid . ' отозвана');
    }
}
