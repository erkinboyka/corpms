<?php

declare(strict_types=1);

namespace App\Http\Controllers\Device;

use App\Domains\Notifications\Services\TelegramService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram Bot API webhook (п.66 ТЗ).
 * Секрет: X-Telegram-Bot-Api-Secret-Token == config('corpms.telegram.webhook_secret').
 */
class TelegramWebhookController extends Controller
{
    public function __construct(protected TelegramService $telegram) {}

    public function handle(Request $request, string $secret): JsonResponse
    {
        if ($secret !== (string) config('corpms.telegram.webhook_secret')) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        // Telegram может слать пакетами; обрабатываем только переданную структуру
        $update = $request->json()->all();
        $handled = $this->telegram->handleUpdate($update);

        // Telegram ждёт 200 в любом случае (не ретраит)
        return response()->json(['ok' => true, 'handled' => $handled]);
    }
}
