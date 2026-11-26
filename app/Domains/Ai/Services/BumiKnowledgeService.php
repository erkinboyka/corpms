<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\DashboardGen\Services\AiClient;
use App\Domains\Documents\Services\DocumentService;
use App\Models\BumiAgent;
use App\Models\BumiKnowledgeItem;
use App\Models\DocumentCategory;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * База знаний Bumi: готовые пары вопрос/ответ + файлы, проиндексированные RAG.
 *
 * Ответ агента опирается на записи самой компании и конкретного агента.
 * Документы ищутся через общий DocumentRagService — тот же механизм, что
 * используется AI-ассистентом и команды ai:index-documents, но с жёстким
 * ограничением по тенанту.
 */
class BumiKnowledgeService
{
    public function __construct(
        protected DocumentRagService $rag,
        protected DocumentService $documents,
        protected AiClient $ai,
    ) {}

    /**
     * Контекст для промпта: сначала явные пары вопрос/ответ, затем фрагменты
     * документов из RAG. Каждый фрагмент caller оборачивает в UntrustedContext.
     *
     * @return array<int, array{title: string, content: string, source: string}>
     */
    public function context(BumiAgent $agent, string $question, int $limit = 5): array
    {
        $question = trim($question);
        if ($question === '') {
            return [];
        }

        $terms = $this->terms($question);
        $items = [];

        $records = BumiKnowledgeItem::query()
            ->where('company_id', $agent->company_id)
            ->where('agent_id', $agent->id)
            ->where('enabled', true)
            ->get();

        foreach ($records as $record) {
            if ((string) $record->kind !== 'qa') {
                continue;
            }
            $text = $record->plainQuestion().' '.$record->plainAnswer();
            $score = $this->score($terms, $text);
            if ($score <= 0) {
                continue;
            }
            $items[] = [
                'title' => $record->title !== '' ? $record->title : $record->plainQuestion(),
                'content' => $record->excerpt(700),
                'source' => 'bumi:qa:'.$record->id,
                'score' => $score,
            ];
        }

        usort($items, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $result = array_slice($items, 0, min(3, $limit));
        foreach ($this->documentContext($agent, $question, max(0, $limit - count($result))) as $chunk) {
            $result[] = $chunk;
        }

        return array_map(static function (array $item): array {
            unset($item['score']);

            return $item;
        }, $result);
    }

    /** Загруженный файл попадает и в документы (RAG-индекс), и в базу Bumi. */
    public function storeFile(BumiAgent $agent, Employee $actor, UploadedFile $file, ?string $title = null): BumiKnowledgeItem
    {
        $category = DocumentCategory::query()->firstOrCreate(
            ['code' => 'bumi_kb'],
            ['name' => __('База знаний Bumi'), 'is_active' => true],
        );

        $name = trim((string) ($title ?: $file->getClientOriginalName()));
        $body = null;
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        if (in_array($extension, ['txt', 'csv', 'md', 'json'], true)) {
            $raw = (string) @file_get_contents($file->getRealPath());
            $body = Str::limit($raw, (int) config('corpms.ai.rag.max_extract_chars', 500000), '');
        }

        $document = $this->documents->create($actor, [
            'category_id' => $category->id,
            'title' => $name !== '' ? $name : __('Файл базы знаний Bumi'),
            'body' => $body,
        ], $file);

        try {
            $this->rag->indexDocument($document, true);
        } catch (Throwable) {
            // Файл остаётся доступным: индекс можно перестроить командой ai:index-documents.
        }

        return BumiKnowledgeItem::query()->create([
            'company_id' => $agent->company_id,
            'agent_id' => $agent->id,
            'kind' => 'file',
            'title' => $name !== '' ? $name : __('Файл базы знаний Bumi'),
            'filename' => $file->getClientOriginalName(),
            'meta' => ['document_id' => $document->id],
            'enabled' => true,
        ]);
    }

    /** Идемпотентный стартовый пакет вопросов и ответов. */
    public function seedPack(BumiAgent $agent): int
    {
        $created = 0;
        foreach (self::pack() as $pair) {
            $item = BumiKnowledgeItem::query()->firstOrCreate([
                'company_id' => $agent->company_id,
                'agent_id' => $agent->id,
                'kind' => 'qa',
                'question' => $pair['question'],
            ], [
                'title' => $pair['title'],
                'answer' => $pair['answer'],
                'enabled' => true,
            ]);
            if ($item->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /** @return array<int, array{title: string, question: string, answer: string}> */
    public static function pack(): array
    {
        $pairs = [
            ['Как с вами связаться?', 'Связаться с нами можно через этот чат, по телефону или на сайте компании. Оставьте контакт — мы передадим обращение ответственному сотруднику.'],
            ['Какое время работы?', 'Работаем по будням. Уточните, пожалуйста, ваш город — проверю актуальное расписание в базе знаний.'],
            ['Вы работаете в выходные?', 'Как правило, обращения в выходные обрабатываются в следующий рабочий день. Если вопрос срочный, оставьте телефон.'],
            ['Сколько стоит ваша услуга?', 'Стоимость зависит от задачи. Назовите, что именно вас интересует, и я подскажу, где посмотреть актуальные условия.'],
            ['Как заказать консультацию?', 'Напишите, какая задача вас интересует, и оставьте телефон — мы уточним детали и предложим удобное время.'],
            ['Как быстро вы отвечаете?', 'В рабочее время отвечаем максимально быстро. Если все сотрудники заняты, мы вернёмся к вам после получения обращения.'],
            ['Куда отправлять документы?', 'Отправьте документы через чат или уточните у сотрудника адрес для вашего города. Я помогу передать обращение дальше.'],
            ['Можно ли изменить или отменить заявку?', 'Да. Назовите номер заявки или данные, по которым её можно найти, и мы уточним, что можно изменить.'],
        ];

        return array_map(static fn (array $pair): array => [
            'title' => $pair[0],
            'question' => $pair[0],
            'answer' => '<p>'.e($pair[1]).'</p>',
        ], $pairs);
    }

    /**
     * Автогенерация примеров вопросов: сначала AI, затем детерминированный
     * запасной набор. Возвращает только строки questions.
     *
     * @return array<int, string>
     */
    public function generateQuestions(BumiAgent $agent, int $count = 5): array
    {
        $count = max(3, min(8, $count));
        $prompt = "Придумай {$count} коротких реальных вопросов, которые клиенты могут задать ассистенту по этой теме.\n"
            .'Один вопрос на строку, без нумерации, без ответов.';

        try {
            $result = $this->ai->chatText($prompt, $this->generationSystem($agent), 500, $agent->model ?: null);
            $text = is_string($result['content'] ?? null) ? trim((string) $result['content']) : '';
            $lines = [];
            foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
                $line = trim((string) $line, " \t\n\r\0\x0B-–—*0123456789.).");
                if ($line !== '' && mb_strlen($line) >= 8 && mb_strlen($line) <= 180) {
                    $lines[] = $line;
                }
            }
            if (count($lines) >= 3) {
                return array_slice(array_values(array_unique($lines)), 0, $count);
            }
        } catch (Throwable) {
            // локальный набор ниже
        }

        return array_slice(array_map(static fn (array $pair): string => $pair['question'], self::pack()), 0, $count);
    }

    protected function generationSystem(BumiAgent $agent): string
    {
        $parts = [
            'Ты помогаешь настроить чат-бота Bumi. Генерируй только вопросы клиентов.',
            'Используй как контекст описание агента и его базу знаний, если они есть.',
        ];
        $description = trim(html_entity_decode(strip_tags((string) $agent->description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($description !== '') {
            $parts[] = 'Описание агента: '.Str::limit($description, 800);
        }
        $knowledge = trim(html_entity_decode(strip_tags((string) $agent->knowledge), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($knowledge !== '') {
            $parts[] = 'База знаний: '.Str::limit($knowledge, 2000);
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<int, array{title: string, content: string, source: string}>
     */
    protected function documentContext(BumiAgent $agent, string $question, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $employees = Employee::query()
            ->where('company_id', $agent->company_id)
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$agent->default_owner_id])
            ->limit(3)
            ->get();

        foreach ($employees as $employee) {
            try {
                $chunks = $this->rag->retrieve($employee, $question, $limit);
            } catch (Throwable) {
                continue;
            }
            if ($chunks === []) {
                continue;
            }

            return array_map(static fn (array $chunk): array => [
                'title' => (string) ($chunk['title'] ?? __('Документ')),
                'content' => Str::limit(html_entity_decode(strip_tags((string) ($chunk['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 600),
                'source' => 'rag:document:'.(int) ($chunk['document_id'] ?? 0),
            ], $chunks);
        }

        return [];
    }

    /** @return array<int, string> */
    protected function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 3)));
    }

    /** @param array<int, string> $terms */
    protected function score(array $terms, string $text): float
    {
        $lower = mb_strtolower($text);
        $score = 0.0;
        foreach ($terms as $term) {
            $score += mb_substr_count($lower, $term);
        }

        return $score;
    }
}
