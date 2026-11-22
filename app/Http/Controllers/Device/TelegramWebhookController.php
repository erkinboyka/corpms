<?php

declare(strict_types=1);

namespace App\Http\Controllers\Device;

use App\Domains\Notifications\Services\TelegramService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram Bot API webhook (п.66 ТЗ).
 *
 * SEC-06/BE-04: секрет не включается в URL — Telegram передаёт его в
 * X-Telegram-Bot-Api-Secret-Token. Сравнение constant-time, пустая конфигурация
 * всегда отклоняется. До обработки сервисом апдейт валидируется.
 */
class TelegramWebhookController extends Controller
{
    private const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function __construct(protected TelegramService $telegram) {}

    public function handle(Request $request): JsonResponse
    {
        $configured = (string) config('corpms.telegram.webhook_secret');
        $provided = (string) $request->header(self::SECRET_HEADER, '');

        if ($configured === '' || ! hash_equals($configured, $provided)) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $update = $request->validate([
            'update_id' => ['required', 'integer'],
            'message' => ['sometimes', 'array'],
            'message.text' => ['required_with:message', 'string'],
            'message.chat' => ['required_with:message', 'array'],
            'message.chat.id' => ['required_with:message', 'integer'],
            'edited_message' => ['sometimes', 'array'],
            'channel_post' => ['sometimes', 'array'],
            'callback_query' => ['sometimes', 'array'],
        ]);

        // Telegram может слать только переданную структуру; сервис решаёт,
        // поддерживается ли конкретный тип апдейта.
        $handled = $this->telegram->handleUpdate($update);

        // Telegram ждёт 200 в любом случае (не ретраит).
        return response()->json(['ok' => true, 'handled' => $handled]);
    }
}
