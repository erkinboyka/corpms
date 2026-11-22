<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use Throwable;

/**
 * AI-01/AI-02/AI-05: единственный источник истины для признака grounded.
 *
 * grounded = true только тогда, когда у ответа есть проверяемые источники:
 *  - инструмент выполнен сервером, ссылки получены из его результата;
 *  - модель явно сослалась на пронумерованный RAG-фрагмент [doc:N].
 *
 * Просто непустой текст LLM и «факты в промпте» источником не являются.
 */
final class AiGrounding
{
    /** @var int */
    private const MAX_ITEMS = 8;

    /**
     * Нормализация источников: только безопасные относительные ссылки,
     * ненулевая подпись, дедупликация и жёсткий потолок.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array{type: string, label: string, url?: string}>
     */
    public static function normalize(array $items): array
    {
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = isset($item['label']) && is_string($item['label'])
                ? trim($item['label'])
                : '';
            if ($label === '') {
                continue;
            }

            $type = isset($item['type']) && is_string($item['type']) && $item['type'] !== ''
                ? $item['type']
                : 'source';
            $url = self::safeUrl($item['url'] ?? null);
            $key = $type.'|'.$label.'|'.($url ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $source = [
                'type' => mb_substr($type, 0, 32),
                'label' => mb_substr($label, 0, 140),
            ];
            if ($url !== null) {
                $source['url'] = $url;
            }
            $out[] = $source;

            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{type: string, label: string, url?: string}>  ...$groups
     * @return array<int, array{type: string, label: string, url?: string}>
     */
    public static function merge(array ...$groups): array
    {
        $all = [];
        foreach ($groups as $group) {
            array_push($all, ...$group);
        }

        return self::normalize($all);
    }

    /**
     * Явные ссылки модели на RAG-фрагменты. Номера проверяются по фактически
     * переданному контексту — модель не может выдумать источник.
     *
     * @param  array<int, array{document_id: int, title: string, number: string, score: float, content: string}>  $rag
     * @param  array<int, array{type: string, label: string, url?: string}>  $sources  дополняется найденными источниками
     * @return string ответ без служебных маркеров [doc:N]
     */
    public static function applyRagCitations(array $rag, string $answer, array &$sources): string
    {
        if (preg_match_all('/\[doc:(\d+)\]/iu', $answer, $matches) > 0) {
            foreach ($matches[1] as $number) {
                $item = $rag[(int) $number - 1] ?? null;
                if (! is_array($item)) {
                    continue;
                }

                $documentId = (int) ($item['document_id'] ?? 0);
                $title = trim((string) ($item['title'] ?? ''));
                $numberText = trim((string) ($item['number'] ?? ''));
                $label = $numberText !== '' ? $title.' ('.$numberText.')' : $title;
                if ($label === '') {
                    continue;
                }

                $url = null;
                if ($documentId > 0) {
                    try {
                        $url = route('documents.show', $documentId, false);
                    } catch (Throwable) {
                        $url = null;
                    }
                }

                $sources[] = [
                    'type' => 'document',
                    'label' => $label,
                    'url' => $url,
                ];
            }
        }

        $cleaned = preg_replace('/\s*\[doc:\d+\]/iu', '', $answer) ?? $answer;
        $cleaned = preg_replace('/[ \t]+([.,!?;:])/u', '$1', $cleaned) ?? $cleaned;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $cleaned) ?? $cleaned);
    }

    /** Только относительные пути без схемы/протокола — как в ai.blocks. */
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        return preg_match('~^/(?!/)[^\x00-\x20\\\\]*$~D', $url) === 1 ? $url : null;
    }
}
