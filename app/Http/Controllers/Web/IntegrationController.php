<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\SettingsService;
use Illuminate\View\View;

/**
 * Интеграции (integrations.manage, ТЗ): состояние внешних подсистем.
 * Страница только для чтения — конфигурация правится через .env/config.
 */
class IntegrationController extends Controller
{
    public function index(): View
    {
        $telegramToken = config('services.telegram.bot_token') ?? config('corpms.telegram.bot_token');
        $mailConfig = config('mail.mailers.smtp');

        // Статус AI отражает фактическую конфигурацию (SettingsService: БД → config),
        // как её видит AiAssistantService: base_url и api_key обязаны быть заданы.
        $settings = app(SettingsService::class);
        $aiConfigured = $settings->get('corpms.ai.base_url') !== ''
            && $settings->get('corpms.ai.api_key') !== '';

        return view('admin.integrations', [
            'integrations' => [
                [
                    'name' => __('Telegram-бот уведомлений'),
                    'description' => __('Уведомления о задачах, согласованиях и решениях в Telegram (п.66 ТЗ).'),
                    'status' => $telegramToken !== null && $telegramToken !== '' ? 'configured' : 'not_configured',
                    'details' => $telegramToken !== null && $telegramToken !== ''
                        ? __('bot_token задан, привязка через TelegramLink')
                        : __('установите TELEGRAM_BOT_TOKEN'),
                ],
                [
                    'name' => __('Live-обновления посещаемости'),
                    'description' => __('Push-обновления Live-экрана посещаемости.'),
                    'status' => 'polling',
                    'details' => __('JSON-фид /attendance/live/feed + polling 10 с (Reverb заменён поллингом — без composer-зависимостей).'),
                ],
                [
                    'name' => __('Почта (SMTP)'),
                    'description' => __('Отправка OTP-кодов и уведомлений по e-mail.'),
                    'status' => ($mailConfig['host'] ?? null) !== null && ($mailConfig['host'] ?? null) !== ''
                        ? 'configured'
                        : 'not_configured',
                    'details' => 'host: '.($mailConfig['host'] ?? '—'),
                ],
                [
                    'name' => __('Биометрические терминалы'),
                    'description' => __('Ингресс событий устройств по API-токену (п.24 ТЗ).'),
                    'status' => 'native',
                    'details' => __('POST /api/biometric/events, X-Device-Token; токен выдаётся при регистрации устройства.'),
                ],
                [
                    'name' => __('Файловое хранилище'),
                    'description' => __('Версии документов и кадры биометрии.'),
                    'status' => config('filesystems.default') === 'local' ? 'local' : config('filesystems.default'),
                    'details' => 'disks.local → storage/app',
                ],
                [
                    'name' => __('AI-ассистент'),
                    'description' => __('AI-факты + интеграция с LLM (п.68 ТЗ).'),
                    'status' => $aiConfigured ? 'configured' : 'fallback',
                    'details' => $aiConfigured
                        ? __('внешний LLM подключён')
                        : __('используется локальный аналитик без внешнего API'),
                ],
            ],
            'settingsPage' => route('settings.index'),
        ]);
    }
}
