<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Documents\Services\DocumentService;
use App\Domains\Documents\Services\SignatureService;
use App\Domains\Tasks\Services\TaskService;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\User;
use App\Support\NumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P1 BL-07/DB-07/DB-08: уникальные номера вместо COUNT()+1. */
class NumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Employee $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Seq Co', 'code' => 'seqco']);
        $user = User::factory()->create();
        $this->author = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_no' => 'SEQ-1',
            'first_name' => 'Seq',
            'last_name' => 'Author',
            'status' => 'active',
        ]);
    }

    public function test_sequence_increments_and_ignores_later_seeds(): void
    {
        $numbers = app(NumberGenerator::class);
        $year = now()->format('Y');

        $this->assertSame(1, $numbers->next('test.counter', $year, fn () => 0));
        $this->assertSame(2, $numbers->next('test.counter', $year, fn () => 0));
        // Seed используется только при первом создании строки.
        $this->assertSame(3, $numbers->next('test.counter', $year, fn () => 999));

        $this->assertSame(1, NumberSequence::query()->where('type', 'test.counter')->count());
        $this->assertSame(3, (int) NumberSequence::query()->where('type', 'test.counter')->value('last_value'));
    }

    public function test_seed_starts_after_existing_legacy_numbers(): void
    {
        $numbers = app(NumberGenerator::class);
        $year = now()->format('Y');
        $prefix = 'Д-'.$year.'-';

        Document::create([
            'company_id' => $this->company->id,
            'document_number' => $prefix.'000100',
            'category_id' => DocumentCategory::create(['code' => 'SEQ'.random_int(100, 999), 'name' => 'Seq'])->id,
            'title' => 'Легаси',
            'status' => 'draft',
        ]);

        $value = $numbers->next('document.number', $year, fn () => $numbers->maxSuffix(
            $prefix,
            Document::query()->pluck('document_number'),
        ));

        $this->assertSame(101, $value);
    }

    public function test_created_tasks_and_documents_have_unique_numbers(): void
    {
        $tasks = app(TaskService::class);
        $documents = app(DocumentService::class);
        $category = DocumentCategory::create(['code' => 'SEQB'.random_int(100, 999), 'name' => 'Seq batch']);

        $taskNumbers = [];
        $documentNumbers = [];
        for ($i = 0; $i < 12; $i++) {
            $taskNumbers[] = $tasks->create($this->author, ['title' => 'Seq task '.$i])->number;
            $documentNumbers[] = $documents->create($this->author, [
                'category_id' => $category->id,
                'title' => 'Seq doc '.$i,
                'body' => 'body',
            ])->document_number;
        }

        $this->assertCount(12, array_unique($taskNumbers));
        $this->assertCount(12, array_unique($documentNumbers));
        $this->assertSame(12, (int) NumberSequence::query()->where('type', 'task.number.'.$this->company->id)->value('last_value'));
        $this->assertSame(12, (int) NumberSequence::query()->where('type', 'document.number')->value('last_value'));
    }

    /** PERF-11/DB-08: третий генератор (подписи) тоже использует последовательность. */
    public function test_signature_ids_use_number_sequence(): void
    {
        $signatures = app(SignatureService::class);
        $method = new \ReflectionMethod(SignatureService::class, 'nextSignatureId');

        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $method->invoke($signatures);
        }

        self::assertCount(12, array_unique($ids));
        self::assertSame(12, (int) NumberSequence::query()->where('type', 'signature.id')->value('last_value'));
    }

    public function test_reg_numbers_are_scoped_per_company_and_unique(): void
    {
        $documents = app(DocumentService::class);
        $category = DocumentCategory::create(['code' => 'SEQR'.random_int(100, 999), 'name' => 'Seq reg']);

        $otherUser = User::factory()->create();
        $otherCompany = Company::create(['name' => 'Seq Co 2', 'code' => 'seqco2']);
        $otherAuthor = Employee::create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherUser->id,
            'employee_no' => 'SEQ-2',
            'first_name' => 'Seq',
            'last_name' => 'Other',
            'status' => 'active',
        ]);

        $first = $documents->create($this->author, ['category_id' => $category->id, 'title' => 'A']);
        $second = $documents->create($this->author, ['category_id' => $category->id, 'title' => 'B']);
        $other = $documents->create($otherAuthor, ['category_id' => $category->id, 'title' => 'C']);

        $reflection = new \ReflectionMethod(DocumentService::class, 'nextRegNumber');
        $numbers = [];
        foreach ([$first, $second] as $document) {
            $numbers[] = $reflection->invoke($documents, $document->company_id);
        }
        $numbers[] = $reflection->invoke($documents, $other->company_id);

        $this->assertCount(2, array_unique(array_slice($numbers, 0, 2)));
        $this->assertSame($numbers[0], $numbers[2], 'Нумерация reg_number независима по компаниям');
        $this->assertSame(2, (int) NumberSequence::query()->where('type', 'document.reg_number.'.$this->company->id)->value('last_value'));
        $this->assertSame(1, (int) NumberSequence::query()->where('type', 'document.reg_number.'.$otherCompany->id)->value('last_value'));
    }
}
