<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentSignature;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkflow;
use App\Models\DocumentWorkflowStep;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-контур документооборота через UI (п.51–63 ТЗ):
 * создание → версия → согласование → регистрация → запрос подписи → подпись → верификация.
 */
class DocumentsHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Test Co', 'code' => 'testco']);
        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Админов', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->actingAs($this->adminUser);

        // workflow: один шаг — автор
        DocumentWorkflow::create([
            'company_id' => $this->company->id,
            'name' => 'Стандарт',
            'is_active' => true,
        ]);
        DocumentWorkflowStep::create([
            'workflow_id' => DocumentWorkflow::max('id'),
            'step_order' => 1,
            'name' => 'Автор',
            'actor_type' => 'author',
            'required_action' => 'approve',
            'is_terminal' => true,
        ]);
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

    public function test_documents_index_paginates_searches_and_counts_metrics_over_all_rows(): void
    {
        $category = DocumentCategory::create(['code' => 'PGN', 'name' => 'Пагинация']);
        $uniqueMarker = 'УникальныйНайденныйДокумент';

        for ($i = 1; $i <= 30; $i++) {
            Document::create([
                'company_id' => $this->company->id,
                'author_id' => $this->adminEmployee->id,
                'category_id' => $category->id,
                'title' => ($i === 7 ? $uniqueMarker : 'Документ пагинации №'.$i),
                'document_number' => sprintf('Д-П-%03d', $i),
                'status' => $i <= 3 ? 'signed' : 'draft',
                'confidentiality' => 'internal',
            ]);
        }

        // 1) список листается (25 из 30), а метрики считаются по всем записям scope
        $response = $this->get('/documents')->assertOk();

        $metrics = collect($response->viewData('metrics'))->keyBy('label');
        $this->assertSame(30, $metrics['Всего документов']['value']);
        $this->assertSame(3, $metrics['Подписано и зарегистрировано']['value']);
        $this->assertSame(25, $response->viewData('documents')->count());

        // 2) фильтр статуса не искажает метрики по всем записям
        $filtered = $this->get('/documents?status=draft')->assertOk();
        $filteredMetrics = collect($filtered->viewData('metrics'))->keyBy('label');
        $this->assertSame(30, $filteredMetrics['Всего документов']['value']);
        $this->assertSame(27, $filtered->viewData('documents')->total());

        // 3) поиск по названию/номеру работает и сохраняет пагинацию
        $this->get('/documents?q='.$uniqueMarker)
            ->assertOk()
            ->assertSee($uniqueMarker)
            ->assertDontSee('Документ пагинации №1');

        $this->get('/documents?q=Д-П-029')
            ->assertOk()
            ->assertSee('Документ пагинации №29');
    }

    public function test_full_document_lifecycle_via_ui(): void
    {
        // страница
        $this->get('/documents')->assertOk()->assertSee('Документы');

        // создание
        $category = DocumentCategory::create(['code' => 'LET', 'name' => 'Служебные письма']);
        $this->post('/documents', [
            'title' => 'О закупке сервера',
            'category_id' => $category->id,
            'body' => 'Тестовое тело документа',
            'priority' => 'medium',
            'confidentiality' => 'internal',
        ])->assertRedirect();

        $document = Document::query()->where('title', 'О закупке сервера')->sole();
        $this->assertSame('draft', $document->status->value);
        $this->assertStringStartsWith('Д-', $document->document_number);

        // карточка
        $this->get('/documents/'.$document->id)
            ->assertOk()
            ->assertSee('Тестовое тело документа');

        // вторая версия
        $this->post('/documents/'.$document->id.'/version', [
            'body' => 'Тестовое тело документа v2',
            'change_note' => 'Добавили деталь',
        ])->assertRedirect()->assertSessionHas('status');

        $document->refresh();
        $this->assertEquals(2, DocumentVersion::query()->where('document_id', $document->id)->count());

        // отправка на согласование
        $this->post('/documents/'.$document->id.'/submit')
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('submitted', $document->refresh()->status->value);

        // согласование (автор — админ employee)
        $this->post('/documents/'.$document->id.'/decide', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('approved', $document->refresh()->status->value);

        // регистрация
        $this->post('/documents/'.$document->id.'/register')
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('registered', $document->refresh()->status->value);
        $this->assertStringStartsWith('Р-', $document->reg_number);

        // запрос подписи на себя
        $this->post('/documents/'.$document->id.'/signatures/request', [
            'signer_ids' => [$this->adminEmployee->id],
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('signing', $document->refresh()->status->value);

        $signatureRequest = $document->refresh()->signatureRequests()->sole();
        $this->assertSame('pending', $signatureRequest->status->value);

        // подпись
        $this->post('/documents/'.$document->id.'/signatures/'.$signatureRequest->id.'/sign', [])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('signed', $document->refresh()->status->value);
        $signature = DocumentSignature::query()->where('document_id', $document->id)->sole();
        $this->assertSame('valid', $signature->status->value);
        $this->assertTrue((bool) $signature->fresh()->documentVersion->immutable);

        // верификация
        $this->post('/signatures/'.$signature->id.'/verify')
            ->assertRedirect()
            ->assertSessionHas('status', function ($value) {
                return str_contains((string) $value, 'valid');
            });

        // архив
        $this->post('/documents/'.$document->id.'/archive')
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('archived', $document->refresh()->status->value);
    }

    public function test_owner_scope_hides_foreign_document(): void
    {
        $plainUser = User::factory()->create();
        $plain = $this->employee('P-1', 'Пётр', 'Сторонний', $plainUser->id);
        EmployeeRole::create([
            'employee_id' => $plain->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $author = $this->employee('AU-1', 'Анна', 'Авторша');
        $category = DocumentCategory::create(['code' => 'SEC', 'name' => 'Конфиденциальное']);

        $document = Document::create([
            'company_id' => $this->company->id,
            'document_number' => 'Д-2026-900001',
            'category_id' => $category->id,
            'title' => 'Секретный документ',
            'author_id' => $author->id,
            'status' => 'draft',
        ]);

        // employee: documents.view = own → чужой документ не виден в списке и на карточке
        $this->actingAs($plainUser);

        $this->get('/documents')->assertOk()->assertDontSee('Секретный документ');
        $this->get('/documents/'.$document->id)->assertForbidden();
    }

    public function test_rejection_requires_comment(): void
    {
        $category = DocumentCategory::create(['code' => 'ORD', 'name' => 'Приказы']);
        $this->post('/documents', [
            'title' => 'Приказ №4',
            'category_id' => $category->id,
            'body' => 'Тело приказа',
        ])->assertRedirect();

        $document = Document::query()->where('title', 'Приказ №4')->sole();

        $this->post('/documents/'.$document->id.'/submit')->assertRedirect();
        $document->refresh();
        $this->assertSame('submitted', $document->status->value);

        $this->post('/documents/'.$document->id.'/decide', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('approved', $document->refresh()->status->value);
    }

    /**
     * B2: нумерация учитывает soft-delete (unique-индекс documents.document_number
     * глобальный) и защищена от коллизии при повторе/гонке.
     */
    public function test_document_numbering_survives_soft_delete_and_repeats(): void
    {
        $category = DocumentCategory::create(['code' => 'NUM', 'name' => 'Нумерация']);
        $year = now()->format('Y');

        // 250 документов подряд
        for ($i = 1; $i <= 250; $i++) {
            Document::create([
                'company_id' => $this->company->id,
                'author_id' => $this->adminEmployee->id,
                'category_id' => $category->id,
                'title' => 'Документ нумерации №'.$i,
                'document_number' => sprintf('Д-%s-%06d', $year, $i),
                'status' => 'draft',
                'confidentiality' => 'internal',
            ]);
        }

        // soft-delete последнего и одного из середины
        Document::query()->where('document_number', sprintf('Д-%s-%06d', $year, 250))->delete();
        Document::query()->where('document_number', sprintf('Д-%s-%06d', $year, 100))->delete();

        // раньше count() без withTrashed давал 248 → номер 249 → дубль → падение на unique-индексе
        $this->post('/documents', [
            'title' => 'После удаления',
            'category_id' => $category->id,
            'body' => 'Первый после удалений',
        ])->assertRedirect();

        // повтор/гонка: второй документ сразу за первым — номер не должен совпасть
        $this->post('/documents', [
            'title' => 'Повтор сразу за первым',
            'category_id' => $category->id,
            'body' => 'Второй после удалений',
        ])->assertRedirect();

        $numbers = Document::withTrashed()->pluck('document_number');
        $this->assertSame($numbers->count(), $numbers->unique()->count(), 'Номера документов обязаны быть уникальны с учётом soft-deleted');
        $this->assertSame(sprintf('Д-%s-%06d', $year, 251), Document::query()->where('title', 'После удаления')->sole()->document_number);
        $this->assertSame(sprintf('Д-%s-%06d', $year, 252), Document::query()->where('title', 'Повтор сразу за первым')->sole()->document_number);
    }

    /** B2: регистрационный номер не переиспользуется после soft-delete документа. */
    public function test_reg_number_not_reused_after_soft_delete(): void
    {
        $category = DocumentCategory::create(['code' => 'REG', 'name' => 'Регистрация']);

        $makeApproved = function (string $title) use ($category): Document {
            $this->post('/documents', [
                'title' => $title,
                'category_id' => $category->id,
                'body' => 'Тело',
            ])->assertRedirect();

            $document = Document::query()->where('title', $title)->sole();
            $this->post('/documents/'.$document->id.'/submit')->assertRedirect();
            $this->post('/documents/'.$document->id.'/decide', ['decision' => 'approved'])->assertRedirect();

            return $document;
        };

        $first = $makeApproved('Первый регистрируемый');
        $this->post('/documents/'.$first->id.'/register')->assertRedirect();
        $this->assertSame('Р-'.now()->format('Y').'-000001', $first->refresh()->reg_number);

        // удалённый зарегистрированный документ не должен «освобождать» свой номер
        $first->delete();

        $second = $makeApproved('Второй регистрируемый');
        $this->post('/documents/'.$second->id.'/register')->assertRedirect();

        $year = now()->format('Y');
        $this->assertSame('Р-'.$year.'-000002', $second->refresh()->reg_number);

        $regNumbers = Document::withTrashed()->whereNotNull('reg_number')->pluck('reg_number');
        $this->assertSame($regNumbers->count(), $regNumbers->unique()->count());
    }

    /** B3: type валидируется по enum DocumentType — 'order'/'contract' больше не проходят. */
    public function test_store_rejects_type_outside_document_type_enum(): void
    {
        $category = DocumentCategory::create(['code' => 'TYP', 'name' => 'Типы']);

        $this->from('/documents')
            ->post('/documents', [
                'title' => 'Документ с мусорным типом',
                'category_id' => $category->id,
                'body' => 'Тело',
                'type' => 'contract',
            ])
            ->assertRedirect('/documents')
            ->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('documents', ['title' => 'Документ с мусорным типом']);

        // допустимое значение из enum создаёт документ
        $this->post('/documents', [
            'title' => 'Исходящий документ',
            'category_id' => $category->id,
            'body' => 'Тело',
            'type' => 'outgoing',
        ])->assertRedirect();

        $this->assertSame('outgoing', Document::query()->where('title', 'Исходящий документ')->sole()->type->value);
    }
}
