<?php

declare(strict_types=1);

namespace App\Domains\Biometric\Contracts;

use App\Models\BiometricDevice;
use Illuminate\Http\Request;

/**
 * Абстракция биометрического устройства (п. 25, 74 ТЗ).
 *
 * Laravel не знает вендора; конкретная модель подключается драйвером.
 * Регистрация/перечень устройств и выдача API-токенов — в BiometricService.
 */
interface BiometricDeviceContract
{
    /** Захват события с устройства ("лицо распознано") */
    public function receiveAttendanceEvent(BiometricDevice $device): ?array;

    /** Опрос статуса: online/offline + last seen */
    public function getDeviceStatus(BiometricDevice $device): array;

    public function enrollFace(BiometricDevice $device): ?array;

    public function verifyFace(BiometricDevice $device): ?array;
}
