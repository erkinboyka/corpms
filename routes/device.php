<?php

use App\Http\Controllers\Device\BiometricEventController;
use App\Http\Controllers\Device\RfidEventController;
use App\Http\Controllers\Device\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * Роуты для биометрических терминалов + kiosk + Telegram webhook.
 * Аутентификация: Bearer-токен устройства (biometric_devices.api_token_hash).
 */
Route::prefix('api/v1/biometric/device')->middleware(['device.token', 'throttle:120,1'])->group(function () {
    Route::post('/events', [BiometricEventController::class, 'store'])->name('device.biometric.events');
    Route::post('/rfid', [RfidEventController::class, 'store'])->name('device.rfid.events');
    Route::get('/status', [BiometricEventController::class, 'status'])->name('device.biometric.status');
});

// kiosk не авторизован устройством: read-only feed недавних проходов (публичный по IP — в prod ограничить сетью офиса)
Route::get('/api/v1/kiosk/attendance/{deviceToken}/recent', [BiometricEventController::class, 'recentKioskEvents'])
    ->middleware(['device.token', 'throttle:60,1'])
    ->name('device.kiosk.recent');

// Telegram webhook (п.66 ТЗ): секрет передаётся только в header
// X-Telegram-Bot-Api-Secret-Token — в URL и access-логи не попадает.
Route::post('/api/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('throttle:60,1,telegram.webhook')
    ->name('device.telegram.webhook');
