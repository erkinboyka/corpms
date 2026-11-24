<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use App\Support\AiEndpointGuard;
use App\Support\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Вызов OpenAI-совместимого API для генерации дашбордов.
 *
 * Тот же контур безопасности, что у Bumi: настройки компании через
 * SettingsService, SSRF-guard по endpoint, запредиректный HTTP-клиент.
 * В отличие от Datavue здесь не нужен openai-php — только /chat/completions.
 */
class AiClient
{
    public function __construct(private ?SettingsService $settings = null)
    {
        $this->settings ??= app(SettingsService::class);
    }

    public function enabled(): bool
    {
        $baseUrl = rtrim((string) $this->settings->get('corpms.ai.base_url'), '/');

        return $baseUrl !== ''
            && (string) $this->settings->get('corpms.ai.api_key') !== ''
            && AiEndpointGuard::isAllowed($baseUrl.'/chat/completions');
    }

    /**
     * Запрос к модели. Возвращает:
     *  - content — текст ответа (или null при сбое);
     *  - json    — распарсенный JSON-объект, если ответ был JSON (или null);
     *  - error   — описание сбоя (или null);
     *  - tokens  — использованные токены.
     *
     * @return array{content: ?string, json: ?array, error: ?string, tokens: int}
     */
    public function chat(string $userPrompt, ?string $system = null, int $maxTokens = 3000): array
    {
        $empty = ['content' => null, 'json' => null, 'error' => null, 'tokens' => 0];

        if (!$this->enabled()) {
            return $empty + ['error' => 'AI не настроен: проверьте AI_BASE_URL и AI_API_KEY.'];
        }

        $baseUrl = rtrim((string) $this->settings->get('corpms.ai.base_url'), '/');
        $apiKey = (string) $this->settings->get('corpms.ai.api_key');
        $model = (string) ($this->settings->get('corpms.ai.model') ?: 'gpt-4o-mini');
        $timeout = max(5, (int) ($this->settings->get('corpms.ai.timeout') ?: 60));

        $messages = [];

        if ($system !== null && $system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        if (!AiEndpointGuard::isAllowed($baseUrl.'/chat/completions')) {
            return $empty + ['error' => 'AI endpoint заблокирован политикой безопасности.'];
        }

        // Провайдеры вроде Groq ограничивают TPM: 429/413 — норма при
        // генерации нескольких виджетов подряд. Ждём и повторяем, а при
        // "request too large" ещё и уменьшаем потолок токенов ответа.
        $tokens = max(1, $maxTokens);
        $attempt = 0;
        $backoff = 1;

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($apiKey)
                    ->timeout($timeout)
                    ->withoutRedirecting()
                    ->post($baseUrl.'/chat/completions', [
                        'model' => $model,
                        'messages' => $messages,
                        'max_tokens' => $tokens,
                        'response_format' => ['type' => 'json_object'],
                    ]);
            } catch (Throwable $e) {
                Log::warning('DashboardGen: AI недоступен', ['error' => $e->getMessage()]);

                return $empty + ['error' => 'Не удалось связаться с AI: '.$e->getMessage()];
            }

            if ($response->successful()) {
                break;
            }

            $status = $response->status();
            $detail = Str::limit(trim(strip_tags($response->body())), 300, '');
            $retryable = in_array($status, [413, 429, 500, 502, 503, 504], true);

            if (!$retryable || $attempt >= 4) {
                Log::warning('DashboardGen: AI ответил ошибкой', ['status' => $status]);

                return $empty + ['error' => 'AI вернул ошибку HTTP '.$status.'. '.$detail];
            }

            if ($status === 413 && $tokens > 500) {
                $tokens = max(500, intdiv($tokens, 2));
            }

            $retryAfter = (int) $response->header('Retry-After');
            sleep($retryAfter > 0 ? min(30, $retryAfter) : min(12, $backoff));
            $backoff *= 2;
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            return $empty + ['error' => 'AI вернул пустой ответ.'];
        }

        $content = trim($content);

        return [
            'content' => $content,
            'json' => self::extractJson($content),
            'error' => null,
            'tokens' => (int) ($response->json('usage.total_tokens') ?? 0),
        ];
    }

    /**
     * Достаёт JSON-объект из ответа: снимает markdown-ограждение, а при
     * неудаче берёт всё от первой "{" до последней "}".
     *
     * @return array<string, mixed>|null
     */
    public static function extractJson(string $text): ?array
    {
        $clean = trim(preg_replace('/```(?:json)?/i', '', $text) ?? $text);

        $decoded = json_decode($clean, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
