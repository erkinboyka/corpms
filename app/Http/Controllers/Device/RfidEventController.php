<?php

declare(strict_types=1);

namespace App\Http\Controllers\Device;

use App\Domains\Biometric\Services\RfidService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Ингресс RFID-ридеров (п.72 ТЗ): Bearer-токен устройства, ответ аналогичен био-API.
 */
class RfidEventController extends Controller
{
    public function __construct(protected RfidService $rfid)
    {
    }

    /** POST /api/v1/biometric/device/rfid — "карта приложена". */
    public function store(Request $request): JsonResponse
    {
        $device = $request->attributes->get('biometric_device');
        \assert($device instanceof BiometricDevice);

        $validated = $request->validate([
            'event_uuid' => ['required', 'string', 'max:64'],
            'card_uid' => ['required', 'string', 'max:64'],
            'direction' => ['required', 'in:in,out'],
            'timestamp' => ['required', 'date'],
        ]);

        try {
            $result = $this->rfid->processEvent(
                $device,
                $validated['event_uuid'],
                $validated['card_uid'],
                $validated['direction'],
                $validated['timestamp'],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Ошибка обработки RFID-события'], 500);
        }

        $status = $result['status'];

        if ($status === 'duplicate') {
            return response()->json([
                'status' => 'duplicate',
                'message' => 'Событие уже обработано (idempotency по event_uuid)',
            ]);
        }

        if (in_array($status, ['unknown_card', 'card_disabled', 'card_expired'], true)) {
            return response()->json([
                'status' => $status,
                'message' => 'RFID-карта не принята (' . $status . ')',
            ], 422);
        }

        $payload = [
            'status' => $status,
            'event' => [
                'id' => $result['event']->id,
                'event_uuid' => $result['event']->event_uuid,
                'processing_status' => $result['event']->processing_status->value,
                'direction' => $result['event']->direction,
            ],
        ];

        if ($status === 'skipped') {
            return response()->json($payload, 422);
        }

        /** @var AttendanceRecord $record */
        $record = $result['record'];

        /** @var Employee $employee */
        $employee = $result['employee'];

        $payload['attendance'] = [
            'date' => $record->date->toDateString(),
            'status' => $record->status->value,
            'clock_in_at' => $record->clock_in_at?->toDateTimeString(),
            'clock_out_at' => $record->clock_out_at?->toDateTimeString(),
            'late_minutes' => $record->late_minutes,
            'worked_minutes' => $record->worked_minutes,
        ];

        $payload['employee'] = [
            'employee_no' => $employee->employee_no,
            'full_name' => $employee->last_name . ' ' . $employee->first_name,
        ];

        return response()->json($payload);
    }
}
