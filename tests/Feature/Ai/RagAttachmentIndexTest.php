<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domains\Ai\Services\DocumentRagService;
use App\Domains\Ai\Services\OcrEngine;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentChunk;
use App\Models\DocumentRagIndex;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * RAG-01: вложения PDF/DOCX и изображения участвуют в поиске с источником и
 * версией индекса; скан без текстового слоя распознаётся через Tesseract,
 * повреждённый файл и отсутствие OCR-бинарников не ломают индексацию,
 * поиск остаётся в границах прав и компании.
 */
class RagAttachmentIndexTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $admin;

    protected Employee $worker;

    protected DocumentCategory $category;

    protected Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake('local');

        $this->company = Company::create(['name' => 'RAG Attach Co', 'code' => 'ragattach']);
        $this->category = DocumentCategory::create(['code' => 'RAGA', 'name' => 'Вложения']);

        $this->adminUser = User::factory()->create();
        $this->admin = $this->employee('A-1', 'Админ', 'Индексатор', $this->adminUser->id);
        $this->giveRole($this->admin, 'super_admin');

        $workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Ольга', 'Рядовая', $workerUser->id);
        $this->giveRole($this->worker, 'employee');

        $this->document = Document::create([
            'company_id' => $this->company->id,
            'author_id' => $this->admin->id,
            'category_id' => $this->category->id,
            'title' => 'Приказ по бюджету',
            'document_number' => 'Д-RAG-ATT-1',
            'body' => 'Тело документа упоминает БЮДЖЕТ.',
            'status' => 'draft',
            'confidentiality' => 'internal',
        ]);

        $this->actingAs($this->adminUser);
    }

    /** DOCX: текст вложения индексируется отдельным чанком с источником и версией. */
    public function test_docx_attachment_text_is_indexed_with_source_and_version(): void
    {
        $version = $this->addFileVersion(
            $this->document,
            $this->makeDocx('RAGDOCXMARKER пункт 4.2'),
            'appendix.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $rag = app(DocumentRagService::class);
        self::assertGreaterThan(1, $rag->indexDocument($this->document));

        $fileChunk = DocumentChunk::query()
            ->where('document_id', $this->document->id)
            ->where('source', 'file:docx')
            ->firstOrFail();

        self::assertStringContainsString('RAGDOCXMARKER', (string) $fileChunk->content);
        self::assertSame($version->id, $fileChunk->document_version_id);
        self::assertSame($version->sha256, $fileChunk->source_version);

        $state = DocumentRagIndex::query()->where('document_id', $this->document->id)->firstOrFail();
        self::assertSame('indexed', $state->status);
        self::assertSame('text', $state->extraction_status);

        $results = $rag->retrieve($this->admin, 'RAGDOCXMARKER');
        self::assertNotEmpty($results);
        self::assertSame($this->document->id, $results[0]['document_id']);
        self::assertSame('file:docx', $results[0]['source']);
        self::assertSame($version->sha256, $results[0]['source_version']);
        self::assertSame($version->id, $results[0]['document_version_id']);
        self::assertSame(1, $results[0]['index_version']);
    }

    /** PDF: извлекается текстовый слой, источник — file:pdf. */
    public function test_pdf_text_layer_is_indexed(): void
    {
        $version = $this->addFileVersion(
            $this->document,
            $this->makePdf('RAGPDFMARKER условие договора'),
            'contract.pdf',
            'application/pdf',
        );

        $rag = app(DocumentRagService::class);
        $rag->indexDocument($this->document);

        $chunk = DocumentChunk::query()
            ->where('document_id', $this->document->id)
            ->where('source', 'file:pdf')
            ->firstOrFail();

        self::assertStringContainsString('RAGPDFMARKER', (string) $chunk->content);
        self::assertSame($version->id, $chunk->document_version_id);

        $state = DocumentRagIndex::query()->where('document_id', $this->document->id)->firstOrFail();
        self::assertSame('text', $state->extraction_status);

        $results = $rag->retrieve($this->admin, 'RAGPDFMARKER');
        self::assertNotEmpty($results);
        self::assertSame('file:pdf', $results[0]['source']);
    }

    /** Скан PDF без текстового слоя распознаётся Tesseract и идёт в поиск. */
    public function test_scanned_pdf_is_ocr_indexed_with_source_and_status(): void
    {
        self::assertTrue(app(OcrEngine::class)->supportsPdf(), 'Tesseract + pdftoppm должны быть установлены');

        $version = $this->addFileVersion(
            $this->document,
            $this->makeScannedPdf('RAGSCAN MARKER 77'),
            'scan.pdf',
            'application/pdf',
        );

        $rag = app(DocumentRagService::class);
        $rag->indexDocument($this->document);

        $chunk = DocumentChunk::query()
            ->where('document_id', $this->document->id)
            ->where('source', 'file:pdf+ocr')
            ->firstOrFail();
        self::assertStringContainsString('RAGSCAN', (string) $chunk->content);
        self::assertStringContainsString('MARKER', (string) $chunk->content);
        self::assertSame($version->sha256, $chunk->source_version);

        $state = DocumentRagIndex::query()->where('document_id', $this->document->id)->firstOrFail();
        self::assertSame('ocr', $state->extraction_status);
        self::assertSame('indexed', $state->status);
        self::assertNotNull($state->extracted_at);

        $results = $rag->retrieve($this->admin, 'RAGSCAN');
        self::assertNotEmpty($results);
        self::assertSame($this->document->id, $results[0]['document_id']);
        self::assertSame('file:pdf+ocr', $results[0]['source']);
    }

    /** Отдельное изображение распознаётся как вложение с источником file:image+ocr. */
    public function test_standalone_image_attachment_is_ocr_indexed(): void
    {
        self::assertTrue(app(OcrEngine::class)->supportsImages(), 'Tesseract должен быть установлен');

        $this->addFileVersion(
            $this->document,
            $this->makePngWithText('RAGIMAGE MARKER 88'),
            'scan.png',
            'image/png',
        );

        $rag = app(DocumentRagService::class);
        $rag->indexDocument($this->document);

        $chunk = DocumentChunk::query()
            ->where('document_id', $this->document->id)
            ->where('source', 'file:image+ocr')
            ->firstOrFail();
        self::assertStringContainsString('RAGIMAGE', (string) $chunk->content);

        $state = DocumentRagIndex::query()->where('document_id', $this->document->id)->firstOrFail();
        self::assertSame('ocr', $state->extraction_status);

        $results = $rag->retrieve($this->admin, 'RAGIMAGE');
        self::assertNotEmpty($results);
        self::assertSame('file:image+ocr', $results[0]['source']);
    }

    /** Без OCR-бинарников скан не даёт file-чанков, но body продолжает искаться. */
    public function test_missing_ocr_binary_records_unsupported_and_keeps_body_index(): void
    {
        config([
            'corpms.ai.rag.tesseract_path' => sys_get_temp_dir().'/no-such-tesseract.exe',
            'corpms.ai.rag.pdftoppm_path' => sys_get_temp_dir().'/no-such-pdftoppm.exe',
        ]);

        $this->addFileVersion($this->document, $this->makeScannedPdf('RAGNOOCR MARKER 99'), 'scan.pdf', 'application/pdf');

        $rag = app(DocumentRagService::class);
        $rag->indexDocument($this->document);

        self::assertFalse(DocumentChunk::query()
            ->where('document_id', $this->document->id)
            ->where('source', 'like', 'file:%')
            ->exists());

        $state = DocumentRagIndex::query()->where('document_id', $this->document->id)->firstOrFail();
        self::assertSame('unsupported', $state->extraction_status);
        self::assertSame('unsupported', $state->status);
        self::assertNotNull($state->error);

        $results = $rag->retrieve($this->admin, 'БЮДЖЕТ');
        self::assertNotEmpty($results, 'Текст body продолжает индексироваться');
        self::assertSame('document', $results[0]['source']);
    }

    /** Повреждённый файл: ошибка фиксируется, индексация title/body не падает. */
    public function test_corrupted_attachment_records_failed_extraction_without_breaking_index(): void
    {
        $this->addFileVersion($this->document, 'это не DOCX', 'broken.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $rag = app(DocumentRagService::class);
        $count = $rag->indexDocument($this->document);

        self::assertGreaterThan(0, $count);
        self::assertFalse(DocumentChunk::query()
            ->where('document_id', $this->document->id)
            ->where('source', 'like', 'file:%')
            ->exists());

        $state = DocumentRagIndex::query()->where('document_id', $this->document->id)->firstOrFail();
        self::assertSame('failed', $state->extraction_status);
        self::assertNotNull($state->error);
        self::assertSame('indexed', $state->status);

        $results = $rag->retrieve($this->admin, 'БЮДЖЕТ');
        self::assertNotEmpty($results);
    }

    /** Переиндексация меняет версию индекса и удаляет текст старой версии файла. */
    public function test_force_reindex_replaces_old_file_text_and_bumps_index_version(): void
    {
        $this->addFileVersion($this->document, $this->makeDocx('RAGDOCXOLD старый текст'), 'old.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $rag = app(DocumentRagService::class);
        $rag->indexDocument($this->document);

        $newVersion = $this->addFileVersion($this->document, $this->makeDocx('RAGDOCXNEW новый текст'), 'new.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $rag->reindexAll((int) $this->company->id, true);

        $contents = DocumentChunk::query()->where('document_id', $this->document->id)->pluck('content')->implode(' ');
        self::assertStringNotContainsString('RAGDOCXOLD', $contents);
        self::assertStringContainsString('RAGDOCXNEW', $contents);
        self::assertSame($newVersion->sha256, DocumentChunk::query()->where('source', 'file:docx')->value('source_version'));

        $state = DocumentRagIndex::query()->where('document_id', $this->document->id)->firstOrFail();
        self::assertSame(2, $state->index_version);

        $this->document->forceDelete();
        self::assertFalse(DocumentChunk::query()->where('document_id', $this->document->id)->exists());
        self::assertFalse(DocumentRagIndex::query()->where('document_id', $this->document->id)->exists());
    }

    /** Повторная индексация без изменений не пересоздаёт чанки и не дёргает embeddings. */
    public function test_reindex_with_same_fingerprint_is_a_noop(): void
    {
        $this->enableAi();
        $this->fakeEmbeddings([1.0, 0.0]);

        $this->addFileVersion($this->document, $this->makeDocx('RAGDOCXSTABLE'), 'stable.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $rag = app(DocumentRagService::class);
        $rag->indexDocument($this->document);

        $ids = DocumentChunk::query()->where('document_id', $this->document->id)->pluck('id')->all();
        $version = DocumentRagIndex::query()->where('document_id', $this->document->id)->value('index_version');

        Http::fake(['llm.example.com/v1/embeddings' => Http::response(['error' => 'should not be called'], 500)]);
        $rag->indexDocument($this->document);

        self::assertSame($ids, DocumentChunk::query()->where('document_id', $this->document->id)->pluck('id')->all());
        self::assertSame($version, DocumentRagIndex::query()->where('document_id', $this->document->id)->value('index_version'));
    }

    /** Текст вложения не утекает в другую компанию и игнорирует documents.view=own. */
    public function test_attachment_text_respects_tenant_and_permission_scope(): void
    {
        $this->addFileVersion($this->document, $this->makeDocx('RAGDOCXPRIVATE'), 'private.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        app(DocumentRagService::class)->indexDocument($this->document);

        $foreignCompany = Company::create(['name' => 'Other', 'code' => 'other-rag']);
        $foreignUser = User::factory()->create();
        $foreignEmployee = Employee::create([
            'company_id' => $foreignCompany->id,
            'user_id' => $foreignUser->id,
            'employee_no' => 'F-1',
            'first_name' => 'Чужой',
            'last_name' => 'Сотрудник',
            'status' => 'active',
        ]);
        $this->giveRole($foreignEmployee, 'super_admin');

        $foreignDoc = Document::create([
            'company_id' => $foreignCompany->id,
            'author_id' => $foreignEmployee->id,
            'category_id' => $this->category->id,
            'title' => 'Чужой приказ',
            'document_number' => 'Д-FOREIGN-1',
            'body' => 'RAGDOCXPRIVATE foreign body',
            'status' => 'draft',
        ]);
        app(DocumentRagService::class)->indexDocument($foreignDoc);

        $results = app(DocumentRagService::class)->retrieve($this->admin, 'RAGDOCXPRIVATE');
        self::assertNotEmpty($results);
        self::assertSame($this->document->id, $results[0]['document_id'], 'Чужая компания не попадает в поиск');
        foreach ($results as $result) {
            self::assertNotSame($foreignDoc->id, $result['document_id']);
        }

        $workerResults = app(DocumentRagService::class)->retrieve($this->worker, 'RAGDOCXPRIVATE');
        self::assertSame([], $workerResults, 'documents.view=own не видит чужой документ с вложением');
    }

    /** Reranking: при равной векторной оценке приоритет у совпадения в заголовке. */
    public function test_reranking_prefers_title_match_with_equal_vector_score(): void
    {
        $this->enableAi();
        $this->fakeEmbeddings([1.0, 0.0]);
        $this->document->forceDelete(); // изолируем двух кандидатов с одинаковой векторной оценкой

        $plain = Document::create([
            'company_id' => $this->company->id,
            'author_id' => $this->admin->id,
            'category_id' => $this->category->id,
            'title' => 'Инструкция',
            'document_number' => 'Д-RAG-R-1',
            'body' => 'Общий текст про отчёт.',
            'status' => 'draft',
        ]);
        $titled = Document::create([
            'company_id' => $this->company->id,
            'author_id' => $this->admin->id,
            'category_id' => $this->category->id,
            'title' => 'Отчётный регламент',
            'document_number' => 'Д-RAG-R-2',
            'body' => 'Общий текст про отчёт.',
            'status' => 'draft',
        ]);
        self::assertTrue($plain->id < $titled->id);

        $results = app(DocumentRagService::class)->retrieve($this->admin, 'отчёт');

        self::assertCount(2, $results);
        self::assertSame($titled->id, $results[0]['document_id'], 'Reranking поднимает документ с совпадением в заголовке');
        self::assertSame(1.0, $results[0]['score'], 'Score остаётся базовой оценкой сходства');
    }

    /** Команда индексации поддерживает принудительное повышение версии индекса. */
    public function test_artisan_command_supports_force_reindex(): void
    {
        $this->addFileVersion($this->document, $this->makeDocx('RAGDOCXCMD'), 'cmd.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->artisan('ai:index-documents', ['--company' => $this->company->id])->assertExitCode(0);
        $this->artisan('ai:index-documents', ['--company' => $this->company->id, '--force' => true])->assertExitCode(0);

        self::assertSame(2, DocumentRagIndex::query()->where('document_id', $this->document->id)->value('index_version'));
    }

    private function employee(string $no, string $first, string $last, int $userId): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    private function giveRole(Employee $employee, string $slug): void
    {
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    private function addFileVersion(Document $document, string $bytes, string $name, string $mime): DocumentVersion
    {
        $path = 'documents/'.$document->id.'/'.$name;
        Storage::disk('local')->put($path, $bytes);

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version' => (int) $document->versions()->max('version') + 1,
            'file_path' => $path,
            'file_name' => $name,
            'mime_type' => $mime,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'created_by' => $this->admin->id,
        ]);
        $document->update(['current_version_id' => $version->id]);

        return $version;
    }

    private function makeDocx(string $text): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ragdocx');
        self::assertIsString($tmp);

        $zip = new ZipArchive;
        self::assertTrue($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>'
            .'</w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /** Минимальный PDF с текстовым слоем (или только с изображением). */
    private function makePdf(?string $text, bool $imageOnly = false): string
    {
        if ($imageOnly) {
            $content = "q\nBI /W 1 /H 1 /BPC 8 /CS /G /F /AHx ID\n00\nEI\nQ\n";
        } else {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $text);
            $content = "BT /F1 12 Tf 72 720 Td ({$escaped}) Tj ET\n";
        }

        $stream = (string) gzcompress($content);
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            '5 0 obj << /Length '.strlen($stream).' /FlateDecode >>'."\nstream\n".$stream."\nendstream endobj",
        ];

        return "%PDF-1.4\n".implode("\n", $objects)."\ntrailer << /Root 1 0 R /Size 6 >>\n%%EOF";
    }

    /** Настоящий скан-PDF: JPEG-изображение с текстом внутри XObject. */
    private function makeScannedPdf(string $text): string
    {
        $image = $this->makeTextImage($text);
        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();
        self::assertTrue(imagejpeg($image, null, 92));
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $content = "q 540 0 0 100 36 650 cm /Im0 Do Q\n";
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream endobj",
            '5 0 obj << /Length '.strlen($content)." >>\nstream\n".$content.'endstream endobj',
        ];

        return "%PDF-1.4\n".implode("\n", $objects)."\ntrailer << /Root 1 0 R /Size 6 >>\n%%EOF";
    }

    private function makePngWithText(string $text): string
    {
        $image = $this->makeTextImage($text);

        ob_start();
        self::assertTrue(imagepng($image));
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** @return \GdImage */
    private function makeTextImage(string $text)
    {
        self::assertTrue(extension_loaded('gd'), 'Для OCR-теста нужен PHP GD');

        $image = imagecreatetruecolor(1400, 260);
        self::assertNotFalse($image);

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 1399, 259, $white);

        $font = $this->fontPath();
        if ($font !== null) {
            imagettftext($image, 64, 0, 40, 170, $black, $font, $text);
        } else {
            imagestring($image, 5, 40, 100, $text, $black);
        }

        return $image;
    }

    private function fontPath(): ?string
    {
        foreach ([
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function enableAi(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);
    }

    /** @param array<int,float> $vector */
    private function fakeEmbeddings(array $vector): void
    {
        Http::fake([
            'llm.example.com/v1/embeddings' => function ($request) use ($vector) {
                $input = array_values((array) ($request->data()['input'] ?? []));
                $data = [];
                foreach ($input as $index => $text) {
                    $data[] = ['index' => $index, 'embedding' => $vector];
                }

                return Http::response(['data' => $data]);
            },
        ]);
    }
}
