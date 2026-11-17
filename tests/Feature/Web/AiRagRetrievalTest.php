<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Ai\Services\DocumentRagService;
use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentChunk;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * D4 (RAG): релевантные фрагменты документов компании в ответах AI.
 *
 * Провайдер embeddings в phpunit-окружении выключен — проверяем оба режима:
 * векторный поиск (Http::fake /embeddings) и keyword-деградацию (провайдер
 * недоступен/ошибается), а также применение прав documents.view при поиске.
 */
class AiRagRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected DocumentCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'RAG Co', 'code' => 'ragco']);
        $this->category = DocumentCategory::create(['code' => 'RAG', 'name' => 'RAG-документы']);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Рагов', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Ольга', 'Рядовая', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    protected function document(string $title, string $body, string $number, ?Employee $author = null): Document
    {
        return Document::create([
            'company_id' => $this->company->id,
            'author_id' => ($author ?? $this->adminEmployee)->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'document_number' => $number,
            'body' => $body,
            'status' => 'draft',
            'confidentiality' => 'internal',
        ]);
    }

    /** Включить LLM/embeddings (в phpunit.xml они выключены). */
    protected function enableAi(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);
    }

    /**
     * Fake /embeddings: «бюджет» в тексте → вектор [1,0], иначе [0,1].
     * Позволяет проверить косинус: совпадение → 1.0, ортогональность → 0 (отсечение).
     */
    protected function fakeEmbeddings(): void
    {
        Http::fake([
            'llm.example.com/v1/embeddings' => function ($request) {
                $input = array_values((array) ($request->data()['input'] ?? []));
                $data = [];
                foreach ($input as $index => $text) {
                    $data[] = [
                        'index' => $index,
                        'embedding' => str_contains(mb_strtolower((string) $text), 'бюджет')
                            ? [1.0, 0.0]
                            : [0.0, 1.0],
                    ];
                }

                return Http::response(['data' => $data]);
            },
        ]);
    }

    /* -------------------- индексация -------------------- */

    public function test_index_without_provider_stores_chunks_without_embeddings(): void
    {
        // AI выключен (phpunit.xml) → провайдер embeddings недоступен
        $document = $this->document('Приказ о бюджете', 'Бюджет утверждается ежегодно.', 'Д-RAG-1');

        $rag = app(DocumentRagService::class);
        $count = $rag->indexDocument($document);

        self::assertGreaterThan(0, $count);

        $chunks = DocumentChunk::query()->where('document_id', $document->id)->get();
        self::assertCount($count, $chunks);
        self::assertNull($chunks->first()->embedding, 'Без провайдера чанк хранится без вектора');

        // без провайдера поиск деградирует до keyword и находит документ
        $results = $rag->retrieve($this->adminEmployee, 'бюджет');
        self::assertNotEmpty($results);
        self::assertSame($document->id, $results[0]['document_id']);
    }

    public function test_artisan_index_documents_command_indexes_chunks(): void
    {
        $this->document('Приказ о бюджете', 'Бюджет утверждается.', 'Д-RAG-1');
        $this->document('Отчёт о продажах', 'Продажи выросли.', 'Д-RAG-2');

        $this->artisan('ai:index-documents')->assertExitCode(0);

        self::assertGreaterThan(0, DocumentChunk::query()->count(), 'Команда создаёт чанки документов');

        // несуществующая компания → код INVALID, индексация не выполняется
        $this->artisan('ai:index-documents', ['--company' => 999999])->assertExitCode(2);
    }

    /* -------------------- векторный поиск -------------------- */

    public function test_vector_search_ranks_by_cosine_and_drops_zero_score(): void
    {
        $this->enableAi();
        $this->fakeEmbeddings();

        $budget = $this->document('Бюджет 2026', 'Расходы по бюджету утверждены.', 'Д-RAG-1');
        $this->document('Отчёт о продажах', 'Продажи выросли в этом квартале.', 'Д-RAG-2');

        $results = app(DocumentRagService::class)->retrieve($this->adminEmployee, 'бюджет');

        self::assertCount(1, $results, 'Ортогональный вектор (cosine=0) отсечён, остаётся только совпадение');
        self::assertSame($budget->id, $results[0]['document_id']);
        self::assertSame(1.0, $results[0]['score']);
    }

    public function test_keyword_fallback_when_embeddings_endpoint_fails(): void
    {
        $this->enableAi();
        Http::fake([
            'llm.example.com/v1/embeddings' => Http::response(['error' => 'embeddings not supported'], 500),
        ]);

        $budget = $this->document('Приказ о бюджете', 'Бюджет утверждается ежегодно.', 'Д-RAG-1');
        $this->document('Отчёт', 'Совсем другой текст.', 'Д-RAG-2');

        $results = app(DocumentRagService::class)->retrieve($this->adminEmployee, 'бюджет');

        self::assertCount(1, $results, 'При ошибке провайдера работает keyword-сопоставление');
        self::assertSame($budget->id, $results[0]['document_id']);
        self::assertNull(
            DocumentChunk::query()->where('document_id', $budget->id)->first()?->embedding,
            'При ошибке провайдера чанк остаётся без вектора',
        );
    }

    /* -------------------- права доступа -------------------- */

    public function test_retrieval_applies_documents_view_scope(): void
    {
        $document = $this->document('Приказ о бюджете', 'Бюджет утверждается.', 'Д-RAG-1', $this->adminEmployee);

        $rag = app(DocumentRagService::class);

        // documents.view=own: чужой документ не попадает в контекст вызывающего
        self::assertSame([], $rag->retrieve($this->worker, 'бюджет'));

        // company-scope super_admin его видит
        $results = $rag->retrieve($this->adminEmployee, 'бюджет');
        self::assertNotEmpty($results);
        self::assertSame($document->id, $results[0]['document_id']);
    }

    /* -------------------- интеграция с /ai -------------------- */

    public function test_ask_sends_rag_fragments_in_system_prompt(): void
    {
        $this->enableAi();
        Http::fake([
            'llm.example.com/v1/embeddings' => function ($request) {
                $input = array_values((array) ($request->data()['input'] ?? []));
                $data = [];
                foreach ($input as $index => $text) {
                    $data[] = [
                        'index' => $index,
                        'embedding' => str_contains(mb_strtolower((string) $text), 'бюджет')
                            ? [1.0, 0.0]
                            : [0.0, 1.0],
                    ];
                }

                return Http::response(['data' => $data]);
            },
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Бюджет на 2026 год утверждён.']]],
            ]),
        ]);

        $this->document('Бюджет 2026', 'Расходы по бюджету утверждены.', 'Д-RAG-1');

        $this->post('/ai', ['question' => 'Какой бюджет на 2026 год?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertTrue((bool) $conversation->grounded, 'LLM ответил на фактах с контекстом документов');
        self::assertSame('Бюджет на 2026 год утверждён.', (string) $conversation->answer);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            $systems = collect($request->data()['messages'])->where('role', 'system');

            return $systems->contains(fn (array $message): bool => str_contains($message['content'], 'Релевантные фрагменты документов'))
                && $systems->contains(fn (array $message): bool => str_contains($message['content'], 'Бюджет 2026'));
        });
    }
}
