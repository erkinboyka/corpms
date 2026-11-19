<?php

declare(strict_types=1);

namespace App\Domains\Biometric\Services;

use App\Models\BiometricDevice;
use App\Models\BiometricProfile;
use App\Models\Employee;
use App\Shared\Enums\DeviceStatus;
use Illuminate\Support\Str;

/**
 * Регистрация устройств, выдача токенов и управление биометрическими профилями.
 * События обрабатываются через DeviceApiController и AttendanceService.
 */
class BiometricService
{
    /** Регистрирует устройство и возвращает новый токен один раз. */
    public function registerDevice(array $attributes): array
    {
        $device = BiometricDevice::query()->updateOrCreate(
            ['serial_number' => $attributes['serial_number'], 'company_id' => $attributes['company_id']],
            collect($attributes)
                ->except(['serial_number', 'api_token_hash'])
                ->merge(['api_token_hash' => hash('sha256', 'placeholder-token'), 'status' => 'online'])
                ->all()
        );

        $token = 'bio_'.Str::random(60);

        $device->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'last_seen_at' => now(),
        ])->save();

        return [$device, $token];
    }

    public function deviceByToken(string $plainToken): ?BiometricDevice
    {
        $hash = hash('sha256', $plainToken);

        $device = BiometricDevice::query()->where('api_token_hash', $hash)->first();
        if ($device === null || $device->status === DeviceStatus::Disabled) {
            return null;
        }

        // Обновляем время последнего запроса только для разрешённых устройств.
        $device->forceFill(['last_seen_at' => now(), 'status' => 'online'])->saveQuietly();

        return $device;
    }

    /** Сохраняет ссылку и хеш шаблона без сырых биометрических данных. */
    public function enrollProfile(Employee $employee, string $vendor, ?string $templateRef, string $templateHash): BiometricProfile
    {
        return BiometricProfile::updateOrCreate(
            ['employee_id' => $employee->id, 'vendor' => $vendor],
            [
                'vendor_template_ref' => $templateRef,
                'template_hash' => $templateHash,
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]
        );
    }

    public function disableProfile(Employee $employee, string $vendor): void
    {
        BiometricProfile::query()
            ->where('employee_id', $employee->id)
            ->where('vendor', $vendor)
            ->update(['status' => 'disabled']);
    }

    public function deleteProfile(Employee $employee, string $vendor): void
    {
        BiometricProfile::query()
            ->where('employee_id', $employee->id)
            ->where('vendor', $vendor)
            ->delete();
    }
}
