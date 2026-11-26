<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domains\Ai\Services\DocumentRagService;
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
 * RAG: векторы разных моделей/размеров нельзя сравнивать косинусом.
 *
 * Провайдер возвращает вектора той же длины, что и у тестов, поэтому
 * размерность задаётся явно через AI_EMBEDDING_DIMENSIONS (corpms.ai.embedding_dimensions).
 */
class RagEmbeddingModelCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'text-embedding-3-small';

    private Company $company;

    private Employee $employee;

    private DocumentCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'RAG Vectors', 'code' => 'ragvectors']);
        $this->category = DocumentCategory::create(['code' => 'RV', 'name' => 'Вектора']);

        $user = User::factory()->create();
        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_no' => 'RV-1',
            'first_name' => 'Админ',
            'last_name' => 'Вектор',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($user);
    }

    private function enableProvider(?int $dimensions = null): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
            'corpms.ai.embedding_model' => self::MODEL,
            'corpms.ai.embedding_dimensions' => $dimensions,
        ]);
    }

    /** @param array<int, float> $vector */
    private function fakeEmbeddings(array $vector, bool $ok = true): void
    {
        Http::fake([
            'llm.example.com/v1/embeddings' => $ok
                ? Http::response(['data' => [['index' => 0, 'embedding' => $vector]]])
                : Http::response(['error' => 'unsupported'], 500),
        ]);
    }

    private function document(string $title): Document
    {
        return Document::create([
            'company_id' => $this->company->id,
            'author_id' => $this->employee->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'document_number' => 'RV-'.$title,
            'body' => 'Служебная записка.',
            'status' => 'draft',
            'confidentiality' => 'internal',
        ]);
    }

    /** @param array<int, float> $vector */
    private function chunk(Document $document, array $vector, ?string $model, string $content = 'Отчёт по продажам'): DocumentChunk
    {
        return DocumentChunk::create([
            'company_id' => $this->company->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => $content,
            'embedding' => $vector,
            'embedding_model' => $model,
        ]);
    }

    public function test_embedding_request_includes_configured_model_and_dimensions(): void
    {
        $this->enableProvider(3);
        $this->fakeEmbeddings([1.0, 0.0, 0.0]);

        app(DocumentRagService::class)->indexDocument($this->document('Бюджет 2'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/embeddings')
            && ($request->data()['model'] ?? null) === self::MODEL
            && ($request->data()['dimensions'] ?? null) === 3);
    }

    public function test_index_document_persists_embedding_model(): void
    {
        $this->enableProvider(3);
        $this->fakeEmbeddings([1.0, 0.0, 0.0]);

        $document = $this->document('Бюджет');
        app(DocumentRagService::class)->indexDocument($document);

        $chunk = DocumentChunk::query()->where('document_id', $document->id)->sole();
        self::assertSame(self::MODEL, $chunk->embedding_model);
        self::assertEquals([1.0, 0.0, 0.0], $chunk->embedding);
    }

    public function test_retrieve_skips_chunks_with_different_dimensions(): void
    {
        $this->enableProvider(3);
        $this->fakeEmbeddings([1.0, 0.0, 0.0]);

        $current = $this->document('Текущий');
        $stale = $this->document('Устаревший');
        $this->chunk($current, [1.0, 0.0, 0.0], self::MODEL);
        $this->chunk($stale, [1.0, 0.0], self::MODEL);

        $results = app(DocumentRagService::class)->retrieve($this->employee, 'бюджет');

        self::assertCount(1, $results);
        self::assertSame($current->id, $results[0]['document_id']);
    }

    public function test_retrieve_skips_chunks_embedded_with_another_model_of_same_width(): void
    {
        $this->enableProvider(3);
        $this->fakeEmbeddings([1.0, 0.0, 0.0]);

        $current = $this->document('Текущий');
        $stale = $this->document('Устаревший');
        $this->chunk($current, [1.0, 0.0, 0.0], self::MODEL);
        $this->chunk($stale, [1.0, 0.0, 0.0], 'text-embedding-3-large');

        $results = app(DocumentRagService::class)->retrieve($this->employee, 'бюджет');

        self::assertCount(1, $results);
        self::assertSame($current->id, $results[0]['document_id']);
    }

    public function test_legacy_chunks_without_model_are_not_trusted(): void
    {
        $this->enableProvider(3);
        $this->fakeEmbeddings([1.0, 0.0, 0.0]);

        $this->chunk($this->document('Без модели'), [1.0, 0.0, 0.0], null);

        self::assertSame([], app(DocumentRagService::class)->retrieve($this->employee, 'бюджет'));
    }

    public function test_incompatible_chunks_fall_back_to_keyword_search(): void
    {
        $this->enableProvider(3);
        $this->fakeEmbeddings([1.0, 0.0, 0.0]);

        $document = $this->document('Бюджет');
        $this->chunk($document, [1.0, 0.0, 0.0], 'text-embedding-3-large', 'Утверждён бюджет на год.');

        $results = app(DocumentRagService::class)->retrieve($this->employee, 'бюджет');

        self::assertCount(1, $results);
        self::assertSame($document->id, $results[0]['document_id']);
    }

    public function test_model_change_invalidates_index_fingerprint(): void
    {
        $this->enableProvider(3);
        $this->fakeEmbeddings([1.0, 0.0, 0.0]);

        $document = $this->document('Бюджет 2026');
        $rag = app(DocumentRagService::class);
        $rag->indexDocument($document);

        $first = DocumentChunk::query()->where('document_id', $document->id)->sole();
        self::assertSame(self::MODEL, $first->embedding_model);

        config()->set('corpms.ai.embedding_model', 'text-embedding-3-large');
        $rag->indexDocument($document);

        $second = DocumentChunk::query()->where('document_id', $document->id)->sole();
        self::assertSame('text-embedding-3-large', $second->embedding_model);
        self::assertNotSame($first->id, $second->id);
    }
}
