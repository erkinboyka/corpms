<?php

declare(strict_types=1);

namespace App\Http\Controllers\Device;

use App\Domains\Attendance\Events\AttendanceRecorded;
use App\Domains\Attendance\Services\AttendanceService;
use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Employee;
use App\Shared\Enums\BiometricEventStatus;
use App\Shared\Enums\DeviceDirection;
use App\Support\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HTTP API для биометрических терминалов (п. 24, 73 ТЗ).
 * Bearer-токен устройства; события с event_uuid — идемпотентность.
 */
class BiometricEventController extends Controller
{
    public function __construct(protected AttendanceService $attendance) {}

    /** Приём события "лицо распознано". */
    public function store(Request $request): JsonResponse
    {
        $device = $request->attributes->get('biometric_device');
        \assert($device instanceof BiometricDevice);

        $validated = $request->validate([
            'event_uuid' => ['required', 'string', 'max:64'],
            'employee_no' => ['required', 'string', 'max:32'],
            'direction' => ['required', 'in:in,out'],
            'timestamp' => ['required', 'date'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'liveness' => ['nullable', 'boolean'],
        ]);

        $data = null;

        try {
            $data = DB::transaction(function () use ($device, $validated, $request): ?array {
                // 1. идемпотентность по event_uuid
                $existing = BiometricEvent::query()->where('event_uuid', $validated['event_uuid'])->first();
                if ($existing !== null) {
                    return null;
                }

                // 2. идентификация сотрудника по табельному номеру
                //    строго в пределах компании устройства (иначе — межтенантный подбор)
                $employee = Employee::query()
                    ->where('employee_no', $validated['employee_no'])
                    ->when($device->company_id !== null, fn ($q) => $q->where('company_id', $device->company_id))
                    ->first();

                if ($employee === null) {
                    BiometricEvent::query()->create([
                        'device_id' => $device->id,
                        'employee_id' => null,
                        'event_uuid' => $validated['event_uuid'],
                        'occurred_at' => $validated['timestamp'],
                        'direction' => $validated['direction'],
                        'confidence' => $validated['confidence'] ?? null,
                        'liveness_passed' => (bool) ($validated['liveness'] ?? true),
                        'liveness_required' => (bool) app(SettingsService::class)->get('corpms.biometric.require_liveness', true),
                        'raw_payload' => $request->all(),
                        'processing_status' => BiometricEventStatus::Failed,
                        'failure_reason' => 'employee not found',
                    ]);

                    return ['status' => 'employee_unknown', 'event' => null, 'record' => null, 'employee' => null];
                }

                // 3. фиксируем raw-событие (BIOMETRIC EVENT LOG, п. 30 ТЗ)
                $event = BiometricEvent::query()->create([
                    'device_id' => $device->id,
                    'employee_id' => $employee->id,
                    'event_uuid' => $validated['event_uuid'],
                    'occurred_at' => $validated['timestamp'],
                    'direction' => $validated['direction'],
                    'confidence' => $validated['confidence'] ?? null,
                    'liveness_passed' => (bool) ($validated['liveness'] ?? true),
                    'liveness_required' => (bool) app(SettingsService::class)->get('corpms.biometric.require_liveness', true),
                    'raw_payload' => $request->all(),
                    'processing_status' => BiometricEventStatus::Pending,
                ]);

                // 3. liveness-проверка (п.73 ТЗ): если включена и не пройдена — событие не принимается
                if ($event->liveness_required && $event->liveness_passed === false) {
                    $event->forceFill([
                        'processing_status' => BiometricEventStatus::Failed,
                        'failure_reason' => 'liveness_failed',
                    ])->save();

                    return ['status' => 'liveness_failed', 'event' => $event, 'record' => null, 'employee' => $employee];
                }

                // 4. несовпадение направления устройства
                if ($device->direction !== DeviceDirection::Both
                    && $device->direction->value !== $validated['direction']) {
                    $event->forceFill([
                        'processing_status' => BiometricEventStatus::Skipped,
                        'failure_reason' => "direction mismatch: device={$device->direction->value} event={$validated['direction']}",
                    ])->save();

                    return [
                        'status' => 'skipped',
                        'event' => $event,
                        'record' => null,
                        'employee' => $employee,
                    ];
                }

                // 5. применяем к табелю
                $record = $this->attendance->applyEvent($employee, $event);

                $event->forceFill([
                    'processing_status' => BiometricEventStatus::Processed,
                    'processed_at' => now(),
                ])->save();

                event(new AttendanceRecorded($record, $event));

                return ['status' => 'processed', 'event' => $event, 'record' => $record, 'employee' => $employee];
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Ошибка обработки события'], 500);
        }

        if ($data === null) {
            return response()->json([
                'status' => 'duplicate',
                'message' => 'Событие уже обработано (idempotency по event_uuid)',
            ], 200);
        }

        if ($data['status'] === 'employee_unknown') {
            return response()->json([
                'status' => 'employee_unknown',
                'message' => 'Сотрудник не опознан',
            ], 422);
        }

        if ($data['status'] === 'liveness_failed') {
            return response()->json([
                'status' => 'liveness_failed',
                'message' => 'Событие отклонено: liveness-проверка обязательна (п.73 ТЗ)',
            ], 422);
        }

        $result = [
            'status' => $data['status'],
            'event' => [
                'id' => $data['event']->id,
                'event_uuid' => $data['event']->event_uuid,
                'processing_status' => $data['event']->processing_status->value,
                'direction' => $data['event']->direction,
            ],
        ];

        if ($data['status'] === 'skipped') {
            return response()->json($result, 422);
        }

        $record = $data['record'];

        $result['attendance'] = [
            'date' => $record->date->toDateString(),
            'status' => $record->status->value,
            'clock_in_at' => $record->clock_in_at?->toDateTimeString(),
            'clock_out_at' => $record->clock_out_at?->toDateTimeString(),
            'late_minutes' => $record->late_minutes,
            'worked_minutes' => $record->worked_minutes,
            'overtime_minutes' => $record->overtime_minutes,
        ];

        $result['employee'] = [
            'employee_no' => $data['employee']->employee_no,
            'full_name' => $data['employee']->last_name.' '.$data['employee']->first_name,
        ];

        return response()->json($result);
    }

    public function status(Request $request): JsonResponse
    {
        $device = $request->attributes->get('biometric_device');
        \assert($device instanceof BiometricDevice);

        return response()->json([
            'id' => $device->id,
            'name' => $device->name,
            'direction' => $device->direction->value,
            'status' => 'online',
            'last_seen_at' => now()->toDateTimeString(),
            'time' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Read-only feed недавних проходов для kiosk-терминала (п.24 ТЗ).
     * Раньше роут ссылался на несуществующий метод — любой запрос давал 500.
     * Отдаёт только события компании устройства (tenant-guard).
     */
    public function recentKioskEvents(Request $request): JsonResponse
    {
        $device = $request->attributes->get('biometric_device');
        \assert($device instanceof BiometricDevice);

        $limit = min((int) $request->query('limit', 20), 100);

        $events = BiometricEvent::query()
            ->whereHas('device', fn ($q) => $q->whereKey($device->id))
            ->with(['employee:id,employee_no,last_name,first_name'])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (BiometricEvent $e) => [
                'occurred_at' => $e->occurred_at?->toDateTimeString(),
                'direction' => $e->direction,
                'employee_no' => $e->employee?->employee_no,
                'full_name' => $e->employee !== null
                    ? $e->employee->last_name.' '.$e->employee->first_name
                    : null,
                'processing_status' => $e->processing_status->value,
            ]);

        return response()->json([
            'device_id' => $device->id,
            'time' => now()->toDateTimeString(),
            'events' => $events,
        ]);
    }
}
