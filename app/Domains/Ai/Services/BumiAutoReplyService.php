<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\DashboardGen\Services\AiClient;
use App\Models\BumiAgent;
use App\Support\UntrustedContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * Генерация автоответа Bumi для внешнего канала.
 *
 * Используется только уже настроенный провайдер corpms.ai.* — Ollama/Gemini
 * никогда не становятся обязательными. Если LLM недоступен, агент отвечает
 * детерминированно (приветствие / строка из базы знаний / fallback).
 */
class BumiAutoReplyService
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly BumiKnowledgeService $knowledge,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function reply(BumiAgent $agent, string $question, array $history = []): string
    {
        return $this->replyWithSource($agent, $question, $history)['reply'];
    }

    /**
     * Ответ вместе с честным источником — нужен в playground, чтобы не выдавать
     * локальный fallback за ответ AI и наоборот.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, source: string, source_label: string, source_title: string}
     */
    public function replyWithSource(BumiAgent $agent, string $question, array $history = []): array
    {
        $question = trim($question);
        if ($question === '') {
            return $this->answer(
                $this->plainText((string) ($agent->greeting ?: __('Здравствуйте! Чем могу помочь?'))),
                'greeting',
                __('Приветствие'),
                (string) $agent->name,
            );
        }

        $context = $this->knowledge->context($agent, $question, 5);
        $system = $this->systemPrompt($agent, $context);
        $userPrompt = $this->userPrompt($question, $history);

        try {
            $result = $this->ai->chatText($userPrompt, $system, 600, $agent->model ?: null);
        } catch (Throwable) {
            $result = ['content' => null, 'error' => 'unexpected'];
        }

        if (is_string($result['content'] ?? null) && trim((string) $result['content']) !== '') {
            $text = trim((string) $result['content']);
            $text = mb_strlen($text) > 2000 ? mb_substr($text, 0, 1997).'…' : $text;

            return $this->answer($text, 'ai', __('AI-провайдер'), (string) ($agent->model ?: 'corpms.ai'));
        }

        return $this->localReply($agent, $question, $context);
    }

    /** @return array{reply: string, source: string, source_label: string, source_title: string} */
    protected function answer(string $reply, string $source, string $label, string $title): array
    {
        return [
            'reply' => $reply,
            'source' => $source,
            'source_label' => $label,
            'source_title' => $title,
        ];
    }

    /**
     * @param array<int, array{title: string, content: string, source: string}> $context
     */
    protected function systemPrompt(BumiAgent $agent, array $context = []): string
    {
        $parts = [
            $this->plainText((string) $agent->system_prompt),
            'Отвечай клиенту кратко и по делу, не обещай того, чего нет в базе знаний.',
            'Если данных недостаточно — задай один уточняющий вопрос или предложи передать обращение сотруднику.',
            UntrustedContext::RULE,
        ];

        $knowledge = $this->plainText((string) $agent->knowledge);
        if ($knowledge !== '') {
            $parts[] = "База знаний агента (используй только по делу):\n".UntrustedContext::wrap('agent_knowledge', $knowledge);
        }

        foreach (array_slice($context, 0, 5) as $index => $item) {
            $parts[] = 'Найденное в базе знаний '.($index + 1).' ('
                .$this->plainText((string) ($item['title'] ?? '')).'): '
                .UntrustedContext::wrap('bumi_knowledge', (string) ($item['content'] ?? ''));
        }

        $fallback = $this->plainText((string) $agent->fallback_reply);
        if ($fallback !== '') {
            $parts[] = 'Если не знаешь ответа, вежливо скажи: '.$fallback;
        }

        return implode("\n\n", array_filter($parts, static fn ($part): bool => trim((string) $part) !== ''));
    }

    /** @param array<int, array{role: string, content: string}> $history */
    protected function userPrompt(string $question, array $history): string
    {
        $lines = [];

        foreach (array_slice($history, -8) as $item) {
            $role = ($item['role'] ?? '') === 'assistant' ? 'Bumi' : 'Клиент';
            $lines[] = $role.': '.UntrustedContext::wrap('channel_history', (string) ($item['content'] ?? ''));
        }

        $lines[] = 'Клиент: '.UntrustedContext::wrap('client_message', $question);

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{title: string, content: string, source: string}> $context
     * @return array{reply: string, source: string, source_label: string, source_title: string}
     */
    protected function localReply(BumiAgent $agent, string $question, array $context = []): array
    {
        $greeting = $this->plainText(trim((string) ($agent->greeting ?: 'Здравствуйте! Чем могу помочь?')));

        if (preg_match('/^(?:привет|здравств|добрый|hi|hello|здоров)/iu', $question) === 1) {
            return $this->answer($greeting, 'greeting', __('Приветствие'), (string) $agent->name);
        }

        foreach ($context as $item) {
            $content = trim((string) ($item['content'] ?? ''));
            if ($content !== '') {
                $source = str_starts_with((string) ($item['source'] ?? ''), 'rag:') ? 'document' : 'knowledge';

                return $this->answer(
                    Str::limit($content, 600),
                    $source,
                    $source === 'document' ? __('Документ') : __('База знаний'),
                    $this->plainText((string) ($item['title'] ?? '')),
                );
            }
        }

        $knowledge = $this->plainText((string) $agent->knowledge);
        if ($knowledge !== '') {
            $words = preg_split('/\s+/u', mb_strtolower($question)) ?: [];
            $significant = array_values(array_filter($words, static fn (string $word): bool => mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $word) ?? '') >= 4));

            foreach (preg_split('/\R+/u', $knowledge) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $lower = mb_strtolower($line);
                foreach ($significant as $word) {
                    $normalized = preg_replace('/[^\p{L}\p{N}]/u', '', $word) ?? '';
                    if ($normalized !== '' && mb_strpos($lower, $normalized) !== false) {
                        return $this->answer(
                            Str::limit($line, 600),
                            'agent_knowledge',
                            __('Общий текст агента'),
                            __('База знаний'),
                        );
                    }
                }
            }
        }

        return $this->answer(
            $this->plainText((string) ($agent->fallback_reply ?: 'Уточните, пожалуйста, вопрос — и я постараюсь ответить точнее.')),
            'fallback',
            __('Fallback-ответ'),
            (string) $agent->name,
        );
    }

    /** TinyMCE хранит HTML; в промпт и в ответ клиенту идёт чистый текст. */
    protected function plainText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
