<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\BumiChannel;
use App\Shared\Enums\BumiChannelType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Отправка ответов Bumi обратно в канал.
 *
 * Все адреса фиксированы в коде: компания не может подсунуть произвольный URL
 * и превратить webhook в SSRF-прокси. Идентификаторы дополнительно валидируются.
 */
class BumiChannelSender
{
    public function send(BumiChannel $channel, string $externalId, string $text): bool
    {
        if ($channel->type === BumiChannelType::Website) {
            // Ответ уже сохранён в БД и забирается виджетом polling-ом.
            return true;
        }

        try {
            return match ($channel->type) {
                BumiChannelType::Telegram => $this->telegram($channel, $externalId, $text),
                BumiChannelType::WhatsApp => $this->whatsAppCloud($channel, $externalId, $text),
                BumiChannelType::Instagram => $this->instagram($channel, $externalId, $text),
                BumiChannelType::GreenApi => $this->greenApi($channel, $externalId, $text),
            };
        } catch (Throwable $e) {
            Log::warning('Bumi channel send failed', [
                'channel_id' => $channel->id,
                'type' => $channel->type->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function telegram(BumiChannel $channel, string $chatId, string $text): bool
    {
        $token = $channel->credential('bot_token') ?: (string) config('corpms.telegram.bot_token');
        if (preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token) !== 1
            || preg_match('/^-?\d+$/', $chatId) !== 1) {
            return false;
        }

        return Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ])->successful();
    }

    protected function whatsAppCloud(BumiChannel $channel, string $to, string $text): bool
    {
        $phoneId = $channel->credential('phone_number_id');
        $token = $channel->credential('access_token');

        if (preg_match('/^\d{6,20}$/', $phoneId) !== 1
            || preg_match('/^\d{6,20}$/', $to) !== 1
            || $token === '') {
            return false;
        }

        return Http::withToken($token)->timeout(8)
            ->post("https://graph.facebook.com/v20.0/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $text],
            ])->successful();
    }

    protected function instagram(BumiChannel $channel, string $recipientId, string $text): bool
    {
        $accountId = $channel->credential('ig_user_id');
        $token = $channel->credential('access_token');

        if (preg_match('/^[A-Za-z0-9_]{4,64}$/', $accountId) !== 1
            || preg_match('/^[A-Za-z0-9_]{4,64}$/', $recipientId) !== 1
            || $token === '') {
            return false;
        }

        return Http::withToken($token)->timeout(8)
            ->post("https://graph.facebook.com/v20.0/{$accountId}/messages", [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $text],
            ])->successful();
    }

    protected function greenApi(BumiChannel $channel, string $chatId, string $text): bool
    {
        $instance = $channel->credential('instance_id');
        $token = $channel->credential('api_token');

        if (preg_match('/^\d{6,20}$/', $instance) !== 1
            || preg_match('/^[A-Za-z0-9]{16,128}$/', $token) !== 1
            || preg_match('/^[A-Za-z0-9_@.\-]+$/', $chatId) !== 1) {
            return false;
        }

        return Http::timeout(8)
            ->post("https://api.green-api.com/waInstance{$instance}/sendMessage/{$token}", [
                'chatId' => $chatId,
                'message' => $text,
            ])->successful();
    }
}
