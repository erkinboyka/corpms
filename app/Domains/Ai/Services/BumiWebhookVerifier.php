<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\BumiChannel;
use Illuminate\Http\Request;

/**
 * Проверка подписи/секрета входящих webhook'ов Bumi.
 *
 * Ни один секрет не передаётся в теле ответа и не пишется в лог. Сравнение
 * всегда constant-time; пустой сохранённый секрет означает «канал не готов».
 */
final class BumiWebhookVerifier
{
    public function telegram(BumiChannel $channel, Request $request): bool
    {
        $configured = $channel->credential('webhook_secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $configured !== '' && $provided !== '' && hash_equals($configured, $provided);
    }

    public function meta(BumiChannel $channel, Request $request): bool
    {
        $secret = $channel->credential('app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || $signature === '' || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /** GET-вызов Meta App Dashboard: challenger возвращается только при верном verify_token. */
    public function metaChallenge(BumiChannel $channel, Request $request): ?string
    {
        $configured = $channel->credential('verify_token');
        $mode = (string) $request->query('hub_mode', $request->query('mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('challenge', ''));

        if ($configured === '' || ! hash_equals($configured, $token) || $mode !== 'subscribe' || $challenge === '') {
            return null;
        }

        return mb_substr($challenge, 0, 255);
    }

    /**
     * Green API присылает токен в заголовке или параметре webhook_token.
     * Параметр допустим для совместимости с панелью провайдера — сам канал
     * уже идентифицируется публичным токеном в пути.
     */
    public function greenApi(BumiChannel $channel, Request $request): bool
    {
        $configured = $channel->credential('webhook_secret');
        $provided = (string) $request->header('X-Green-API-Token', $request->query('webhook_token', $request->query('token', '')));

        return $configured !== '' && $provided !== '' && hash_equals($configured, $provided);
    }
}
