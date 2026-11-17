<?php

declare(strict_types=1);

namespace App\Domains\Biometric\Services;

use App\Models\BiometricDevice;
use App\Models\BiometricProfile;
use App\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Р РµРіРёСЃС‚СЂР°С†РёСЏ СѓСЃС‚СЂРѕР№СЃС‚РІ, РІС‹РґР°С‡Р° С‚РѕРєРµРЅРѕРІ, СЂСѓС‡РЅР°СЏ СЂРµРіРёСЃС‚СЂР°С†РёСЏ bio-РїСЂРѕС„РёР»СЏ.
 * РЎР°Рј РїСЂРёС‘Рј СЃРѕР±С‹С‚РёР№ вЂ” DeviceApiController в†’ AttendanceService.
 */
class BiometricService
{
    /** Р РµРіРёСЃС‚СЂРёСЂСѓРµС‚ РёР»Рё РѕР±РЅРѕРІР»СЏРµС‚ СѓСЃС‚СЂРѕР№СЃС‚РІРѕ Рё РІРѕР·РІСЂР°С‰Р°РµС‚ РѕС‚РєСЂС‹С‚С‹Рј С‚РµРєСЃС‚РѕРј РЅРѕРІС‹Р№ С‚РѕРєРµРЅ. */
    public function registerDevice(array $attributes): array
    {
        $device = BiometricDevice::query()->updateOrCreate(
            ['serial_number' => $attributes['serial_number']],
            collect($attributes)
                ->except(['serial_number', 'api_token_hash'])
                ->merge(['api_token_hash' => hash('sha256', 'placeholder-token'), 'status' => 'online'])
                ->all()
        );

        $token = 'bio_' . Str::random(60);

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
        if ($device === null) {
            return null;
        }

        // touch last seen Р°СЃРёРЅС…СЂРѕРЅРЅРѕ РЅРµ РєСЂРёС‚РёС‡РЅРѕ, РїРёС€РµРј СЃСЂР°Р·Сѓ
        $device->forceFill(['last_seen_at' => now(), 'status' => 'online'])->saveQuietly();

        return $device;
    }

    /** РЎРѕР·РґР°С‘С‚ Р±РёРѕРјРµС‚СЂРёС‡РµСЃРєРёР№ РїСЂРѕС„РёР»СЊ СЃРѕС‚СЂСѓРґРЅРёРєР° (Р±РµР· raw-РґР°РЅРЅС‹С… вЂ” С‚РѕР»СЊРєРѕ СЃСЃС‹Р»РєР° РЅР° С€Р°Р±Р»РѕРЅ). */
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
