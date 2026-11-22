<?php

declare(strict_types=1);

namespace App\Domains\Biometric\Services;

use App\Domains\Attendance\Events\AttendanceRecorded;
use App\Domains\Attendance\Services\AttendanceService;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Employee;
use App\Models\RfidCard;
use App\Shared\Enums\BiometricEventStatus;
use App\Shared\Enums\DeviceDirection;
use App\Shared\Enums\RfidCardStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * RFID-карты и их ингресс в посещаемость (п.72 ТЗ).
 *
 * События с ридеров пишутся в общий журнал biometric_events (template_type=rfid);
 * карта связывает UID с сотрудником. Неопознанные UID фиксируются без привязки сотрудника.
 */
class RfidService
{
    public function __construct(
        protected AttendanceService $attendance,
    ) {}

    /**
     * Привязать карту сотруднику.
     *
     * @throws LogicException если UID уже занят
     */
    public function issueCard(Employee $issuer, Employee $owner, string $cardUid, ?string $label, ?Carbon $expiresAt = null): RfidCard
    {
        $exists = RfidCard::query()->where('card_uid', $cardUid)->exists();
        if ($exists) {
            throw new LogicException(__('Карта с таким UID уже выдана'));
        }

        return RfidCard::create([
            'employee_id' => $owner->id,
            'card_uid' => $cardUid,
            'label' => $label,
            'status' => RfidCardStatus::Active,
            'expires_at' => $expiresAt,
            'issued_by' => $issuer->id,
        ]);
    }

    /** Отзыв карты не удаляет её из журнала: статус disabled, история событий сохраняется. */
    public function revokeCard(RfidCard $card): RfidCard
    {
        $card->forceFill(['status' => RfidCardStatus::Disabled])->save();

        return $card;
    }

    /**
     * Обработка события ридера: идемпотентность по event_uuid, привязка к карте/сотруднику,
     * применение к табелю. Шаблон ответа = массив-структура для устройство-API.
     *
     * @return array{status: string, event: ?BiometricEvent, employee: ?Employee, record: mixed}
     */
    public function processEvent(BiometricDevice $device, string $eventUuid, string $cardUid, string $direction, string $timestamp): array
    {
        return DB::transaction(function () use ($device, $eventUuid, $cardUid, $direction, $timestamp) {
            // 1. идемпотентность
            $existing = BiometricEvent::query()
                ->where('company_id', $device->company_id)
                ->where('event_uuid', $eventUuid)
                ->where('template_type', 'rfid')
                ->first();
            if ($existing !== null) {
                return ['status' => 'duplicate', 'event' => null, 'employee' => null, 'record' => null];
            }

            // 2. карта / сотрудник
            /** @var RfidCard|null $card */
            $card = RfidCard::query()->where('card_uid', $cardUid)->first();
            $employee = $card?->employee;

            // tenant-guard: сотрудник карточки должен принадлежать компании устройства
            if ($employee !== null
                && $device->company_id !== null
                && $employee->company_id !== null
                && (int) $employee->company_id !== (int) $device->company_id) {
                $employee = null;
                $card = null;
            }

            $payload = ['card_uid' => $cardUid, 'via_device' => $device->serial_number];

            if ($card === null) {
                $event = $this->logEvent($device, $eventUuid, $direction, $timestamp, 'unknown_card', $payload, null);

                return ['status' => 'unknown_card', 'event' => $event, 'employee' => null, 'record' => null];
            }

            if ($card->status === RfidCardStatus::Disabled) {
                $event = $this->logEvent($device, $eventUuid, $direction, $timestamp, 'card_disabled', $payload, $employee);

                return ['status' => 'card_disabled', 'event' => $event, 'employee' => $employee, 'record' => null];
            }

            if ($card->expires_at !== null && $card->expires_at->isPast()) {
                $event = $this->logEvent($device, $eventUuid, $direction, $timestamp, 'card_expired', $payload, $employee);

                return ['status' => 'card_expired', 'event' => $event, 'employee' => $employee, 'record' => null];
            }

            // 3. фиксируем журнал
            $event = $this->logEvent($device, $eventUuid, $direction, $timestamp, 'pending', $payload, $employee, BiometricEventStatus::Pending);

            // 4. направление устройства
            if ($device->direction !== DeviceDirection::Both && $device->direction->value !== $direction) {
                $event->forceFill([
                    'processing_status' => BiometricEventStatus::Skipped,
                    'failure_reason' => 'direction mismatch: device='.$device->direction->value.' event='.$direction,
                ])->save();

                return ['status' => 'skipped', 'event' => $event, 'employee' => $employee, 'record' => null];
            }

            // 5. табель
            $record = $this->attendance->applyEvent($employee, $event);
            $event->forceFill([
                'processing_status' => BiometricEventStatus::Processed,
                'processed_at' => now(),
            ])->save();

            event(new AttendanceRecorded($record, $event));

            return ['status' => 'processed', 'event' => $event, 'employee' => $employee, 'record' => $record];
        });
    }

    protected function logEvent(
        BiometricDevice $device,
        string $eventUuid,
        string $direction,
        string $timestamp,
        ?string $failureReason,
        array $payload,
        ?Employee $employee,
        BiometricEventStatus $status = BiometricEventStatus::Failed,
    ): BiometricEvent {
        return BiometricEvent::query()->create([
            'device_id' => $device->id,
            'company_id' => $device->company_id,
            'employee_id' => $employee?->id,
            'event_uuid' => $eventUuid,
            'occurred_at' => $timestamp,
            'direction' => $direction,
            'template_type' => 'rfid',
            'liveness_passed' => true,
            'liveness_required' => false, // к RFID liveness неприменима
            'raw_payload' => $payload,
            'processing_status' => $status,
            'failure_reason' => $status === BiometricEventStatus::Pending ? null : $failureReason,
        ]);
    }
}
