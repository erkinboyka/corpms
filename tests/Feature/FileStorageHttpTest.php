<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Фаза A, задача 3 (файловое хранилище): приватность приватного диска,
 * контролируемое скачивание файлов документов, валидация типов загружаемых файлов.
 */
class FileStorageHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected Employee $adminEmployeeA;

    protected Employee $workerB;

    protected DocumentCategory $category;

    protected Document $documentA;

    protected Document $documentB;

    protected Task $taskA;

    protected Task $taskB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->companyA = Company::create(['name' => 'File Co A', 'code' => 'file-a']);
        $this->companyB = Company::create(['name' => 'File Co B', 'code' => 'file-b']);

        $superAdminRoleId = Role::query()->where('slug', 'super_admin')->value('id');

        $this->adminA = User::factory()->create(['email' => 'file-admin@tenant.test']);
        $this->adminEmployeeA = Employee::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminA->id,
            'employee_no' => 'FA-1',
            'first_name' => 'Файл',
            'last_name' => 'Админ',
            'status' => 'active',
        ]);
        EmployeeRole::create(['employee_id' => $this->adminEmployeeA->id, 'role_id' => $superAdminRoleId]);

        $this->workerB = Employee::create([
            'company_id' => $this->companyB->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'FB-1',
            'first_name' => 'Файл',
            'last_name' => 'Бета',
            'status' => 'active',
        ]);

        $this->category = DocumentCategory::create(['code' => 'FILE', 'name' => 'Файловые']);

        // документ компании A с файлом — через HTTP
        $this->actingAs($this->adminA);
        $this->post('/documents', [
            'title' => 'Договор на хостинг',
            'category_id' => $this->category->id,
            'file' => UploadedFile::fake()->create('hosting.pdf', 64, 'application/pdf'),
        ])->assertRedirect();

        $this->documentA = Document::query()->where('title', 'Договор на хостинг')->sole();

        // документ компании B с файлом — как пользователь компании B
        $adminBUser = User::factory()->create(['email' => 'file-admin-b@tenant.test']);
        $adminBEmployee = Employee::create([
            'company_id' => $this->companyB->id,
            'user_id' => $adminBUser->id,
            'employee_no' => 'FB-2',
            'first_name' => 'Файл',
            'last_name' => 'Бета-админ',
            'status' => 'active',
        ]);
        EmployeeRole::create(['employee_id' => $adminBEmployee->id, 'role_id' => $superAdminRoleId]);

        $this->actingAs($adminBUser);
        $this->post('/documents', [
            'title' => 'Чужой договор',
            'category_id' => $this->category->id,
            'file' => UploadedFile::fake()->create('foreign.pdf', 32, 'application/pdf'),
        ])->assertRedirect();
        $this->documentB = Document::query()->where('title', 'Чужой договор')->sole();

        // задачи обеих компаний
        $this->taskA = Task::create([
            'company_id' => $this->companyA->id,
            'title' => 'Задача хранилища А',
            'number' => 'FS-A-'.uniqid(),
            'type' => 'task',
            'status' => 'new',
            'author_id' => $this->adminEmployeeA->id,
        ]);
        $this->taskB = Task::create([
            'company_id' => $this->companyB->id,
            'title' => 'Задача хранилища Б',
            'number' => 'FS-B-'.uniqid(),
            'type' => 'task',
            'status' => 'new',
            'author_id' => $this->workerB->id,
        ]);

        $this->actingAs($this->adminA);
    }

    /* ---------------- скачивание файлов документов ---------------- */

    public function test_document_file_download_scoped_by_tenant(): void
    {
        $versionA = $this->documentA->versions()->first();
        $this->assertNotNull($versionA);
        $this->assertNotNull($versionA->file_path);

        // свой документ — файл скачивается
        $this->get('/documents/'.$this->documentA->id.'/versions/'.$versionA->id.'/file')
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertDownload('hosting.pdf');

        // чужой документ — 403 даже при documents.view
        $versionB = $this->documentB->versions()->first();
        $this->assertNotNull($versionB);
        $this->get('/documents/'.$this->documentB->id.'/versions/'.$versionB->id.'/file')
            ->assertForbidden();

        // версия чужого документа под маршрутом своего — 404 (не принадлежит документу)
        $this->get('/documents/'.$this->documentA->id.'/versions/'.$versionB->id.'/file')
            ->assertNotFound();
    }

    public function test_private_storage_requires_signed_url(): void
    {
        $versionA = $this->documentA->versions()->first();
        \assert($versionA !== null && $versionA->file_path !== null);

        // прямой GET без подписи — запрещён (защита фреймворка от обхода контроллера)
        $this->get('/storage/'.$versionA->file_path)->assertForbidden();
    }

    /* ---------------- валидация типов файлов ---------------- */

    public function test_document_upload_rejects_unsafe_types(): void
    {
        $this->post('/documents', [
            'title' => 'Опасный файл',
            'category_id' => $this->category->id,
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors(['file']);

        $this->assertDatabaseMissing('documents', ['title' => 'Опасный файл']);
    }

    public function test_task_attachment_rejects_unsafe_types(): void
    {
        $this->post('/tasks/'.$this->taskA->id.'/attachments', [
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors(['file']);
    }

    /* ---------------- приложения задач ---------------- */

    public function test_task_attachment_download_scoped_by_tenant(): void
    {
        // приложение чужой задачи — прямое скачивание запрещено
        $attachment = TaskAttachment::create([
            'task_id' => $this->taskB->id,
            'employee_id' => $this->workerB->id,
            'file_path' => 'tasks/'.$this->taskB->id.'/missing.txt',
            'original_name' => 'secret.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
        ]);

        $this->get('/tasks/'.$this->taskB->id.'/attachments/'.$attachment->id)
            ->assertForbidden();

        // своё приложение: файл на месте после загрузки — скачивание работает (200/404 по наличию файла)
        $this->post('/tasks/'.$this->taskA->id.'/attachments', [
            'file' => UploadedFile::fake()->create('note.txt', 4, 'text/plain'),
        ])->assertRedirect()->assertSessionHas('status');

        $own = TaskAttachment::query()->where('task_id', $this->taskA->id)->sole();
        $this->assertTrue(Storage::disk('local')->exists($own->file_path));

        $this->get('/tasks/'.$this->taskA->id.'/attachments/'.$own->id)
            ->assertOk()
            ->assertDownload('note.txt');
    }
}
