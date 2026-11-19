<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Biometric\Services\BiometricService;
use App\Support\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DeviceTokenAuth: Authorization: Bearer bio_xxx
 * привязывает device к запросу: $request->attributes->set('device', ...)
 */
class DeviceTokenAuth
{
    public function __construct(protected BiometricService $biometric) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $device = $token !== null ? $this->biometric->deviceByToken($token) : null;

        if ($device === null) {
            return response()->json(['message' => 'Устройство не авторизовано'], 401);
        }

        $request->attributes->set('biometric_device', $device);

        // настройки читаются в контексте компании устройства (межтенантность)
        app(SettingsService::class)->useCompany(
            $device->company_id !== null ? (int) $device->company_id : null,
        );

        return $next($request);
    }
}
