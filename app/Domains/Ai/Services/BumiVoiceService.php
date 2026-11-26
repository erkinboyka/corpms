<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Support\AiEndpointGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Серверная диктовка и (опционально) синтез речи через настроенного
 * OpenAI-совместимого провайдера.
 *
 * Ключ берётся из ai_providers компании, поэтому работает и на http://corpms.local,
 * где браузерное распознавание речи запрещено политикой небезопасного origin.
 * Аудио не сохраняется: байты сразу уходят провайдеру и удаляются из памяти.
 */
class BumiVoiceService
{
    public function __construct(protected AiProviderService $providers) {}

    public function isConfigured(): bool
    {
        $credentials = $this->providers->credentials();

        return $credentials['base_url'] !== ''
            && $credentials['api_key'] !== ''
            && AiEndpointGuard::isAllowed($credentials['base_url'].'/audio/transcriptions');
    }

    public function transcribe(UploadedFile $file, ?string $language = null): string
    {
        $credentials = $this->providers->credentials();
        $baseUrl = $credentials['base_url'];
        $apiKey = $credentials['api_key'];
        $model = trim((string) config('corpms.bumi.conversation.stt_model', 'whisper-1'));

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException(__('Серверная диктовка не настроена: добавьте провайдера AI в настройках.'));
        }
        if (! AiEndpointGuard::isAllowed($baseUrl.'/audio/transcriptions')) {
            throw new RuntimeException(__('Адрес провайдера AI не прошёл проверку безопасности.'));
        }

        $language = strtolower((string) $language);
        if (! in_array($language, ['ru', 'en'], true)) {
            $language = 'ru';
        }

        $bytes = $file->getContent();
        if ($bytes === '' || strlen($bytes) > max(1, (int) config('corpms.bumi.conversation.max_audio_bytes', 10 * 1024 * 1024))) {
            throw new RuntimeException(__('Пустой или слишком большой аудиофайл.'));
        }

        $payload = [
            'model' => $model,
            'language' => $language,
            'response_format' => 'json',
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(max(15, $credentials['timeout']))
                ->connectTimeout(10)
                ->withoutRedirecting()
                ->attach('file', $bytes, $file->getClientOriginalName() ?: 'dictation.webm')
                ->post($baseUrl.'/audio/transcriptions', $payload);
        } catch (Throwable $e) {
            Log::warning('Bumi voice: не удалось вызвать STT', ['error' => $e->getMessage()]);

            throw new RuntimeException(__('Не удалось связаться с провайдером распознавания речи.'));
        }

        if ($response->failed()) {
            $detail = (string) ($response->json('error.message') ?? 'unknown error');

            throw new RuntimeException(__('Провайдер распознавания речи вернул ошибку :status: :detail', [
                'status' => $response->status(),
                'detail' => mb_substr($detail, 0, 200),
            ]));
        }

        $text = trim((string) ($response->json('text') ?? $response->json('transcript') ?? ''));
        if ($text === '') {
            throw new RuntimeException(__('Распознавание речи вернуло пустой текст.'));
        }

        return mb_substr($text, 0, 4000);
    }

    /**
     * Синтез через провайдера. Возвращает MIME-тип и байты аудио.
     *
     * @return array{mime: string, bytes: string}
     */
    public function synthesize(string $text, ?string $voice = null): array
    {
        $credentials = $this->providers->credentials();
        $baseUrl = $credentials['base_url'];
        $apiKey = $credentials['api_key'];
        $model = trim((string) config('corpms.bumi.conversation.tts_model', 'gpt-4o-mini-tts'));
        $text = trim(mb_substr($text, 0, 1000));

        if ($baseUrl === '' || $apiKey === '' || $model === '' || $text === '') {
            throw new RuntimeException(__('Серверная озвучка не настроена.'));
        }
        if (! AiEndpointGuard::isAllowed($baseUrl.'/audio/speech')) {
            throw new RuntimeException(__('Адрес провайдера AI не прошёл проверку безопасности.'));
        }

        $payload = [
            'model' => $model,
            'input' => $text,
            'voice' => $voice ?: (string) config('corpms.bumi.conversation.voice_name', 'alloy'),
            'response_format' => 'mp3',
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(max(15, $credentials['timeout']))
                ->connectTimeout(10)
                ->withoutRedirecting()
                ->post($baseUrl.'/audio/speech', $payload);
        } catch (Throwable $e) {
            Log::warning('Bumi voice: не удалось вызвать TTS', ['error' => $e->getMessage()]);

            throw new RuntimeException(__('Не удалось связаться с провайдером озвучки.'));
        }

        if ($response->failed()) {
            throw new RuntimeException(__('Провайдер озвучки вернул ошибку :status', ['status' => $response->status()]));
        }

        $bytes = $response->body();
        if ($bytes === '') {
            throw new RuntimeException(__('Провайдер озвучки вернул пустой ответ.'));
        }

        $mime = strtolower((string) $response->header('Content-Type'));
        $mime = str_contains($mime, 'wav') ? 'audio/wav' : (str_contains($mime, 'opus') ? 'audio/ogg' : 'audio/mpeg');

        return ['mime' => $mime, 'bytes' => $bytes];
    }
}
