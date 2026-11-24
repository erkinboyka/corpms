<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Analytics\Services\AnalyticsService;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentRagIndex;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Support\AiEndpointGuard;
use App\Support\PermissionService;
use App\Support\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * D4 (RAG): релевантные фрагменты документов компании для ответов AI.
 *
 * Индексация: чанкинг текста документа (title/номер/body), текстовый слой
 * PDF/DOCX и OCR сканов/изображений + вектор через OpenAI-совместимый
 * /embeddings (corpms.ai.embeddings_model). Если провайдер недоступен —
 * чанки хранятся без вектора, поиск деградирует до keyword-сопоставления.
 *
 * Права доступа применяются при поиске: documents.view в scope вызывающего
 * (company/department/own — та же логика, что в поиске документов).
 */
class DocumentRagService
{
    /** Максимум неиндексированных документов, проиндексируемых «на лету» за retrieve. */
    private const SELF_HEAL_LIMIT = 10;

    /** Размер чанка и перекрытие, символов. */
    private const CHUNK_SIZE = 1200;

    private const CHUNK_OVERLAP = 150;

    /** Сколько фрагментов (не более одного на документ) уходит в контекст LLM. */
    private const RETRIEVE_LIMIT = 3;

    /** Потолок ожидания /embeddings — та же логика, что corpms.ai.sync_timeout. */
    private const EMBED_TIMEOUT_CAP = 8;

    public function __construct(
        protected AnalyticsService $analytics,
        protected PermissionService $permissions,
        protected AttachmentTextExtractor $extractor,
    ) {}

    /* -------------------- индексация -------------------- */

    /**
     * Индексация документа: title/body + текст или OCR вложения PDF/DOCX/изображения.
     * Повтор без изменений и без --force не пересоздаёт чанки и не вызывает
     * embeddings; при изменении отпечатка поколение индекса увеличивается.
     */
    public function indexDocument(Document $document, bool $force = false): int
    {
        // embeddings-настройки — в контексте компании документа
        app(SettingsService::class)->useCompany(
            $document->company_id !== null ? (int) $document->company_id : null,
        );

        $version = $document->current_version_id !== null
            ? DocumentVersion::query()
                ->whereKey((int) $document->current_version_id)
                ->where('document_id', $document->id)
                ->first()
            : null;

        $fingerprint = hash('sha256', implode("\0", [
            (string) $document->title,
            (string) $document->document_number,
            (string) $document->body,
            (string) $version?->sha256,
            (string) $version?->id,
        ]));

        $state = DocumentRagIndex::query()->firstOrNew(['document_id' => $document->id]);
        $existingChunks = (int) DocumentChunk::query()->where('document_id', $document->id)->count();

        if (! $force
            && $state->exists
            && $state->fingerprint === $fingerprint
            && $existingChunks > 0) {
            return $existingChunks;
        }

        $generation = $state->exists ? (int) $state->index_version + 1 : 1;
        $extraction = $this->extraction($version);
        $chunks = [];

        foreach ($this->chunkText($this->textOf($document)) as $content) {
            $chunks[] = [
                'content' => $content,
                'source' => 'document',
                'source_version' => null,
                'document_version_id' => null,
                'metadata' => null,
            ];
        }

        if ($extraction['text'] !== '' && $extraction['source'] !== null) {
            foreach ($this->chunkText($extraction['text']) as $content) {
                $chunks[] = [
                    'content' => $content,
                    'source' => $extraction['source'],
                    'source_version' => $version?->sha256,
                    'document_version_id' => $version?->id,
                    'metadata' => [
                        'file_name' => (string) $version?->file_name,
                        'mime_type' => (string) $version?->mime_type,
                        'document_version' => (int) ($version?->version ?? 0),
                        'ocr' => $extraction['status'] === AttachmentTextExtractor::OCR,
                    ],
                ];
            }
        }

        $vectors = $chunks === [] ? null : $this->embed(array_column($chunks, 'content'));

        foreach ($chunks as $index => $chunk) {
            $chunks[$index]['company_id'] = $document->company_id;
            $chunks[$index]['document_id'] = $document->id;
            $chunks[$index]['chunk_index'] = $index;
            $chunks[$index]['index_version'] = $generation;
            $chunks[$index]['embedding'] = $vectors[$index] ?? null;
        }

        // Общий статус описывает результат индексации; причина отсутствия
        // текста из вложения лежит в extraction_status (text/ocr/empty/unsupported/failed).
        $status = 'indexed';
        if ($chunks === []) {
            $status = 'empty';
        } elseif ($extraction['status'] === AttachmentTextExtractor::UNSUPPORTED) {
            $status = 'unsupported';
        }

        DB::transaction(function () use ($document, $state, $chunks, $fingerprint, $generation, $status, $extraction): void {
            DocumentChunk::query()->where('document_id', $document->id)->delete();

            foreach ($chunks as $chunk) {
                DocumentChunk::create($chunk);
            }

            $state->fill([
                'company_id' => $document->company_id,
                'fingerprint' => $fingerprint,
                'index_version' => $generation,
                'status' => $status,
                'extraction_status' => $extraction['status'],
                'error' => $extraction['error'],
                'extracted_at' => $extraction['status'] === null ? null : now(),
            ]);
            $state->save();
        });

        return count($chunks);
    }

    /** @return array{text:string,status:?string,source:?string,error:?string} */
    private function extraction(?DocumentVersion $version): array
    {
        if ($version === null || $version->file_path === null || $version->file_path === '') {
            return ['text' => '', 'status' => null, 'source' => null, 'error' => null];
        }

        return $this->extractor->extract($version);
    }

    /**
     * Self-healing: документы компании без чанков (созданы мимо сервиса) —
     * индексируем на лету, не более SELF_HEAL_LIMIT за вызов.
     */
    public function indexMissing(int $companyId): int
    {
        $documents = Document::query()
            ->where('company_id', $companyId)
            ->whereDoesntHave('chunks')
            ->limit(self::SELF_HEAL_LIMIT)
            ->get(['id', 'company_id', 'title', 'document_number', 'reg_number', 'body', 'current_version_id']);

        $count = 0;
        foreach ($documents as $document) {
            $count += $this->indexDocument($document);
        }

        return $count;
    }

    /**
     * Массовая переиндексация (artisan ai:index-documents).
     * $force=true принудительно поднимает поколение индекса у всех документов.
     */
    public function reindexAll(?int $companyId = null, bool $force = false): int
    {
        $count = 0;

        Document::query()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->chunkById(50, function (Collection $documents) use (&$count, $force): void {
                foreach ($documents as $document) {
                    $count += $this->indexDocument($document, $force);
                }
            });

        return $count;
    }

    /* -------------------- поиск -------------------- */

    /**
     * Топ-N фрагментов документов, доступных вызывающему, по релевантности
     * запросу (векторный поиск, при недоступности embeddings — keyword).
     *
     * @return array<int, array{document_id: int, title: string, number: string, score: float, content: string, source: string, source_version: ?string, document_version_id: ?int, index_version: int}>
     */
    public function retrieve(Employee $asker, string $query, int $limit = self::RETRIEVE_LIMIT): array
    {
        // embeddings-настройки — в контекстве компании спрашивающего
        app(SettingsService::class)->useCompany(
            $asker->company_id !== null ? (int) $asker->company_id : null,
        );

        $user = $asker->user;
        if ($user === null || $this->permissions->check($user, 'documents.view') === false) {
            return [];
        }

        $this->indexMissing((int) $asker->company_id);

        $candidates = DocumentChunk::query()
            ->where('company_id', $asker->company_id)
            ->whereIn('document_id', BumiRecords::documents($asker)->select('id'))
            ->with('document:id,company_id,title,document_number,reg_number')
            ->limit(2000)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $queryVector = $this->embed([$query])[0] ?? null;

        $scored = [];

        if ($queryVector !== null) {
            foreach ($candidates as $chunk) {
                if (! is_array($chunk->embedding) || $chunk->embedding === []) {
                    continue;
                }
                $score = $this->cosine($queryVector, $chunk->embedding);
                if ($score > 0) {
                    $scored[] = ['score' => $score, 'chunk' => $chunk];
                }
            }
        }

        if ($scored === []) {
            // деградация: embeddings недоступны (или ни один чанк не векторизован)
            foreach ($candidates as $chunk) {
                $score = $this->keywordScore($query, (string) $chunk->content);
                if ($score > 0) {
                    $scored[] = ['score' => $score, 'chunk' => $chunk];
                }
            }
        }

        if ($scored === []) {
            return [];
        }

        // Reranking: базовая оценка остаётся в score, но порядок дополнительно
        // учитывает совпадение в заголовке и текст из вложения.
        foreach ($scored as &$item) {
            $item['final'] = (float) $item['score'] + $this->rerankBoost(
                $query,
                $item['chunk']->document,
                $item['chunk'],
            );
        }
        unset($item);

        usort($scored, static function (array $a, array $b): int {
            return ($b['final'] <=> $a['final']) ?: ($b['score'] <=> $a['score']);
        });

        $results = [];
        $seenDocuments = [];

        foreach ($scored as $item) {
            $document = $item['chunk']->document;
            if ($document === null) {
                continue;
            }

            $documentId = (int) $document->id;
            if (isset($seenDocuments[$documentId])) {
                continue; // не более одного фрагмента на документ
            }
            $seenDocuments[$documentId] = true;

            $chunk = $item['chunk'];
            $results[] = [
                'document_id' => $documentId,
                'title' => (string) $document->title,
                'number' => (string) $document->document_number,
                'score' => round((float) $item['score'], 4),
                'content' => mb_substr((string) $chunk->content, 0, 400),
                'source' => (string) ($chunk->source ?? 'document'),
                'source_version' => $chunk->source_version !== null ? (string) $chunk->source_version : null,
                'document_version_id' => $chunk->document_version_id !== null ? (int) $chunk->document_version_id : null,
                'index_version' => (int) ($chunk->index_version ?? 1),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /* -------------------- текст и вектора -------------------- */

    /** Текст документа для чанкинга: заголовок, номера, тело (без HTML). */
    protected function textOf(Document $document): string
    {
        $number = 'Номер: '.$document->document_number;
        if ($document->reg_number !== null && $document->reg_number !== '') {
            $number .= ', регистрационный: '.$document->reg_number;
        }

        $parts = [
            (string) $document->title,
            $number,
            trim(strip_tags((string) $document->body)),
        ];

        return implode("\n", array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /**
     * Разбивка текста на чанки ~CHUNK_SIZE символов по словам, с перекрытием.
     *
     * @return array<int, string>
     */
    protected function chunkText(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }

        $length = mb_strlen($text);
        if ($length <= self::CHUNK_SIZE) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;

        while ($offset < $length) {
            $piece = mb_substr($text, $offset, self::CHUNK_SIZE);

            if ($offset + self::CHUNK_SIZE < $length) {
                $cut = mb_strrpos($piece, ' ');
                if ($cut !== false && $cut > intdiv(self::CHUNK_SIZE, 2)) {
                    $piece = mb_substr($piece, 0, $cut);
                }
            }

            $piece = trim($piece);
            if ($piece !== '') {
                $chunks[] = $piece;
            }

            $offset += max(1, mb_strlen($piece) - self::CHUNK_OVERLAP);
        }

        return $chunks;
    }

    /**
     * Векторы через OpenAI-совместимый /embeddings; null — провайдер
     * недоступен (нет ключа/модели/эндпоинта) → keyword-режим.
     *
     * @param  array<int, string>  $input
     * @return array<int, array<int, float>>|null
     */
    protected function embed(array $input): ?array
    {
        $settings = app(SettingsService::class);
        $baseUrl = rtrim((string) $settings->get('corpms.ai.base_url'), '/');
        $apiKey = (string) $settings->get('corpms.ai.api_key');
        $model = (string) ($settings->get('corpms.ai.embeddings_model') ?? '');

        if ($baseUrl === '' || $apiKey === '' || $model === '' || ! AiEndpointGuard::isAllowed($baseUrl.'/embeddings')) {
            return null;
        }

        $configured = (int) ($settings->get('corpms.ai.timeout') ?? 30);
        $cap = (int) config('corpms.ai.sync_timeout', self::EMBED_TIMEOUT_CAP);
        $timeout = $cap > 0 ? min($configured, $cap) : $configured;

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post($baseUrl.'/embeddings', [
                    'model' => $model,
                    'input' => array_values($input),
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json('data');
        if (! is_array($data) || count($data) !== count($input)) {
            return null;
        }

        // провайдер может вернуть вектора в произвольном порядке — восстанавливаем
        usort($data, fn (array $a, array $b): int => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));

        $vectors = [];
        foreach ($data as $row) {
            $embedding = $row['embedding'] ?? null;
            if (! is_array($embedding) || $embedding === []) {
                return null;
            }
            $vectors[] = array_map('floatval', $embedding);
        }

        return $vectors;
    }

    /**
     * Детерминированный reranking: совпадение запроса в заголовке документа и
     * в тексте вложения поднимает фрагмент при равной базовой оценке.
     * Боном не меняет score (совместимость с existing vector/keyword-тестами).
     */
    protected function rerankBoost(string $query, ?Document $document, DocumentChunk $chunk): float
    {
        $tokens = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $token): bool => mb_strlen($token) >= 3,
        ));

        if ($tokens === []) {
            return 0.0;
        }

        $boost = 0.0;

        if ($document !== null) {
            $title = mb_strtolower((string) $document->title);
            $hits = 0;
            foreach ($tokens as $token) {
                if (str_contains($title, $token)) {
                    $hits++;
                }
            }
            $boost += 0.10 * ($hits / count($tokens));
        }

        if (str_starts_with((string) $chunk->source, 'file:')) {
            $content = mb_strtolower((string) $chunk->content);
            foreach ($tokens as $token) {
                if (str_contains($content, $token)) {
                    $boost += 0.05;

                    break;
                }
            }
        }

        return $boost;
    }

    /** Косинусное сходство; размерности разной длины безопасно усекаются. */
    protected function cosine(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /** Keyword-оценка: число слов запроса (≥3 символов), найденных в чанке. */
    protected function keywordScore(string $query, string $content): float
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $haystack = mb_strtolower($content);

        $hits = 0;
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 3 && str_contains($haystack, $token)) {
                $hits++;
            }
        }

        return (float) $hits;
    }
}
