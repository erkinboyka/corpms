<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use App\Domains\Ai\Services\AiProviderService;
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
    public function __construct(
        private ?SettingsService $settings = null,
        private ?AiProviderService $providers = null,
    ) {
        $this->settings ??= app(SettingsService::class);
        $this->providers ??= app(AiProviderService::class);
    }

    /**
     * @return array{provider_id: ?int, provider_name: string, base_url: string, api_key: string, model: string, image_model: string, embedding_model: string, timeout: int}
     */
    public function credentials(?string $preferredModel = null): array
    {
        return $this->providers->credentials($preferredModel);
    }

    public function enabled(): bool
    {
        $credentials = $this->credentials();

        return $credentials['base_url'] !== ''
            && $credentials['api_key'] !== ''
            && AiEndpointGuard::isAllowed($credentials['base_url'].'/chat/completions');
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

        $credentials = $this->credentials();
        $baseUrl = $credentials['base_url'];
        $apiKey = $credentials['api_key'];
        $model = $credentials['model'];
        $timeout = max(5, $credentials['timeout']);

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
     * Обычный текстовый ответ без response_format=json_object.
     *
     * Используется внешними каналами Bumi: там нужен живой текст для клиента,
     * а не JSON-объект. Провайдер, endpoint и ключ берутся из настроек компании,
     * модель можно переопределить настройкой агента.
     *
     * @return array{content: ?string, json: ?array, error: ?string, tokens: int}
     */
    public function chatText(string $userPrompt, ?string $system = null, int $maxTokens = 600, ?string $model = null): array
    {
        $empty = ['content' => null, 'json' => null, 'error' => null, 'tokens' => 0];

        if (!$this->enabled()) {
            return $empty + ['error' => 'AI не настроен: проверьте AI_BASE_URL и AI_API_KEY.'];
        }

        $credentials = $this->credentials($model);
        $baseUrl = $credentials['base_url'];
        $apiKey = $credentials['api_key'];
        $selectedModel = $credentials['model'];
        $timeout = max(5, $credentials['timeout']);

        if (!AiEndpointGuard::isAllowed($baseUrl.'/chat/completions')) {
            return $empty + ['error' => 'AI endpoint заблокирован политикой безопасности.'];
        }
        if (preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $selectedModel) !== 1) {
            return $empty + ['error' => 'Некорректная модель агента.'];
        }

        $messages = [];
        if ($system !== null && trim($system) !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $selectedModel,
                    'messages' => $messages,
                    'max_tokens' => max(1, $maxTokens),
                ]);
        } catch (Throwable $e) {
            Log::warning('Bumi: AI недоступен', ['error' => $e->getMessage()]);

            return $empty + ['error' => 'Не удалось связаться с AI: '.$e->getMessage()];
        }

        if (!$response->successful()) {
            $detail = Str::limit(trim(strip_tags($response->body())), 300, '');

            return $empty + ['error' => 'AI вернул ошибку HTTP '.$response->status().'. '.$detail];
        }

        $content = $response->json('choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            return $empty + ['error' => 'AI вернул пустой ответ.'];
        }

        return [
            'content' => trim($content),
            'json' => null,
            'error' => null,
            'tokens' => (int) ($response->json('usage.total_tokens') ?? 0),
        ];
    }

    /**
     * Запрос к модели с OpenAI tools для Bumi/BI.
     *
     * В отличие от chat() не форсирует response_format=json_object: провайдеры
     * не всегда совмещают его с tool_calls. Возвращает как текст, так и вызовы.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{content: ?string, tool_calls: array<int, array<string, mixed>>, error: ?string, tokens: int}
     */
    public function chatWithTools(array $messages, array $tools): array
    {
        $empty = ['content' => null, 'tool_calls' => [], 'error' => null, 'tokens' => 0];

        if (!$this->enabled()) {
            return $empty + ['error' => 'AI не настроен: проверьте AI_BASE_URL и AI_API_KEY.'];
        }

        $credentials = $this->credentials();
        $baseUrl = $credentials['base_url'];
        $apiKey = $credentials['api_key'];
        $model = $credentials['model'];
        $timeout = max(5, $credentials['timeout']);

        if (!AiEndpointGuard::isAllowed($baseUrl.'/chat/completions')) {
            return $empty + ['error' => 'AI endpoint заблокирован политикой безопасности.'];
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => 1200,
        ];

        // Второй запрос (после исполнения tool_calls) идёт без инструментов:
        // пустой массив tools не все провайдеры принимают.
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (Throwable $e) {
            return $empty + ['error' => 'Не удалось связаться с AI: '.$e->getMessage()];
        }

        if (!$response->successful()) {
            $detail = Str::limit(trim(strip_tags($response->body())), 300, '');

            return $empty + ['error' => 'AI вернул ошибку HTTP '.$response->status().'. '.$detail];
        }

        $message = $response->json('choices.0.message');
        if (!is_array($message)) {
            return $empty + ['error' => 'AI вернул пустой ответ.'];
        }

        $calls = $message['tool_calls'] ?? [];

        return [
            'content' => is_string($message['content'] ?? null) ? trim((string) $message['content']) : null,
            'tool_calls' => is_array($calls) ? $calls : [],
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
