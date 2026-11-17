<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Documents\Services\DocumentService;
use App\Domains\Documents\Services\SignatureService;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentSignatureRequest;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\AttendanceStatus;
use App\Support\SettingsService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HTTP-контур: аналитика, отчёты (+экспорт), аудит, поиск, настройки, дашборд.
 */
class AnalyticsAndSystemHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected Department $department;

    protected Employee $worker;

    protected User $workerUser;

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

        $this->department = Department::create([
            'company_id' => $this->company->id,
            'name' => 'Продажи',
            'code' => 'SALES',
            'director_id' => $this->adminEmployee->id,
        ]);
        $this->adminEmployee->update(['department_id' => $this->department->id]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Иван', 'Иванов', $this->workerUser->id);
        $this->worker->update(['department_id' => $this->department->id]);
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

    /* -------------------- аналитика -------------------- */

    public function test_analytics_page_shows_real_data(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'clock_in_at' => now()->subHours(4),
            'clock_out_at' => now(),
            'late_minutes' => 12,
            'status' => AttendanceStatus::Late,
        ]);

        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Задача аналитика',
            'number' => 'T-1001',
            'author_id' => $this->adminEmployee->id,
            'status' => 'in_progress',
        ])->assignments()->create([
            'assignee_type' => 'employee',
            'employee_id' => $this->worker->id,
            'assignment_status' => 'accepted',
        ]);

        $this->get('/analytics')
            ->assertOk()
            ->assertSee('Аналитика')
            ->assertSee('опоздали: 1')
            ->assertSee('Продажи');
    }

    public function test_analytics_denied_without_permission(): void
    {
        // роль super_admin имеет все права, проверяем на рядовом сотруднике
        $this->actingAs($this->workerUser);
        $this->get('/analytics')->assertForbidden();
    }

    /* -------------------- отчёты -------------------- */

    public function test_attendance_report_aggregates_and_exports(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'clock_in_at' => now()->startOfDay()->addHours(9),
            'clock_out_at' => now()->startOfDay()->addHours(17),
            'late_minutes' => 30,
            'overtime_minutes' => 15,
            'status' => AttendanceStatus::Late,
        ]);

        $this->get('/reports/attendance?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertSee(' Иван')
            ->assertSee('30');

        // экспорт CSV (UTF-8 BOM для Excel)
        $response = $this->get('/reports/attendance/export?from='.today()->toDateString().'&to='.today()->toDateString());
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Иван', $csv);
        $this->assertStringContainsString('30', $csv);

        // фиксация в аудит-журнале
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_tasks_and_hr_reports_render(): void
    {
        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Задача отчёта',
            'number' => 'T-1002',
            'author_id' => $this->adminEmployee->id,
            'status' => 'completed',
        ])->assignments()->create([
            'assignee_type' => 'employee',
            'employee_id' => $this->worker->id,
            'assignment_status' => 'accepted',
        ]);

        $this->get('/reports/tasks')->assertOk()->assertSee('Иванов')->assertSee('Итого');
        $this->get('/reports/hr')->assertOk()->assertSee('Продажи')->assertSee('Админов');
    }

    /**
     * B5: CSV-экспорт не должен порождать запрос на каждого сотрудника
     * (раньше attendance — 1 запрос/строку, tasks — 4 запроса/строку).
     */
    public function test_csv_export_avoids_n_plus_one_queries(): void
    {
        $extraIds = [];
        for ($i = 1; $i <= 30; $i++) {
            $employee = $this->employee('X-'.$i, 'Сотрудник', '№'.$i);
            $extraIds[] = $employee->id;
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'date' => today(),
                'clock_in_at' => now()->startOfDay()->addHours(9),
                'clock_out_at' => now()->startOfDay()->addHours(17),
                'status' => AttendanceStatus::Present,
            ]);
        }

        $countQueries = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get($url)->assertOk();
            $response->streamedContent();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // 30 сотрудников: раньше только в цикле csvAttendance было бы ≥30 запросов,
        // в csvTasks — ≥120. Порог фиксированный и не зависит от числа строк.
        $attendanceQueries = $countQueries('/reports/attendance/export?from='.today()->toDateString().'&to='.today()->toDateString());
        $this->assertLessThanOrEqual(
            20,
            $attendanceQueries,
            "Экспорт посещаемости выполнил {$attendanceQueries} запросов — похоже на N+1 (порог 20)"
        );

        $tasksQueries = $countQueries('/reports/tasks/export?from='.today()->toDateString().'&to='.today()->toDateString());
        $this->assertLessThanOrEqual(
            20,
            $tasksQueries,
            "Экспорт задач выполнил {$tasksQueries} запросов — похоже на N+1 (порог 20)"
        );

        // содержимое корректно: все 30 строк присутствуют
        $csv = $this->get('/reports/attendance/export?from='.today()->toDateString().'&to='.today()->toDateString())
            ->streamedContent();
        $this->assertSame(30, substr_count($csv, 'Сотрудник'), 'В экспорте должны быть все 30 сотрудников');
    }

    /**
     * B5: счётчик непрочитанных уведомлений выполняется один раз на запрос
     * (раньше считался 3 раза: navbar + nav-actions в navbar + nav-actions в sidebar).
     */
    public function test_unread_notifications_count_query_runs_once_per_page(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/dashboard')->assertOk();

        $countQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'app_notifications')
                && str_contains($query['query'], 'read_at'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            1,
            $countQueries,
            "Счётчик уведомлений выполнился {$countQueries} раз за страницу — должен быть один (count по app_notifications)"
        );
    }

    /* -------------------- аудит -------------------- */

    public function test_audit_index_lists_logs_with_filters(): void
    {
        app(AuditService::class)
            ->log($this->adminUser, 'module.action', 'tests', 42, null, ['a' => 'b']);

        $this->get('/audit')
            ->assertOk()
            ->assertSee('module.action')
            ->assertSee($this->adminUser->name);

        $this->get('/audit?action=module')
            ->assertOk()
            ->assertSee('module.action');

        $this->get('/audit?action=nothing_found')
            ->assertOk()
            ->assertSee('Записей нет');
    }

    /* -------------------- поиск -------------------- */

    public function test_global_search_finds_scoped_entities(): void
    {
        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Особенная поисковая задача',
            'number' => 'T-SEARCH',
            'author_id' => $this->adminEmployee->id,
            'status' => 'new',
        ]);

        $this->get('/search?q=Особенная')
            ->assertOk()
            ->assertSee('Особенная поисковая задача')
            ->assertSee('Сотрудники')
            ->assertSee('Документы');

        $this->get('/search?q='.urlencode('W-1'))
            ->assertOk()
            ->assertSee('Иванов');

        $this->get('/search?q='.urlencode('нет такого xy'))
            ->assertOk();
    }

    /* -------------------- настройки -------------------- */

    public function test_settings_view_and_update_via_db(): void
    {
        // просмотр
        $this->get('/settings')
            ->assertOk()
            ->assertSee('Обязательная liveness-проверка')
            // super_admin видит форму
            ->assertSee('Сохранить');

        // сохраняем: OTP TTL = 22 мин
        $this->post('/settings', [
            'settings' => [
                'corpms.tasks.max_subtask_depth' => 4,
                'corpms.signature.otp_ttl' => 22,
                'corpms.biometric.require_liveness' => '1',
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $settingsService = app(SettingsService::class);
        $this->assertSame(22, $settingsService->get('corpms.signature.otp_ttl'));
        $this->assertSame(4, $settingsService->get('corpms.tasks.max_subtask_depth'));

        // повторное чтение нового инстанса — данные из БД
        $fresh = new SettingsService;
        $this->assertSame(22, $fresh->get('corpms.signature.otp_ttl'));

        // настройки действительно применяются: OTP живёт 22 минуты
        $category = DocumentCategory::create(['code' => 'ORD', 'name' => 'Приказы']);
        $documentService = app(DocumentService::class);
        $document = $documentService->create($this->adminEmployee, [
            'title' => 'Документ для OTP',
            'category_id' => $category->id,
            'body' => 'Тело',
        ]);
        $document->update(['status' => 'registered']);

        app(SignatureService::class)
            ->requestSignatures($this->adminEmployee, $document, [$this->worker->id], true);

        /** @var DocumentSignatureRequest $signatureRequest */
        $signatureRequest = DocumentSignatureRequest::query()->where('document_id', $document->id)->sole();
        app(SignatureService::class)->issueOtp($signatureRequest);
        $signatureRequest->refresh();
        $expiresAt = now()->parse($signatureRequest->meta['otp_expires_at']);
        $diff = $expiresAt->diffInMinutes(now(), absolute: true);

        $this->assertGreaterThanOrEqual(21, $diff);
        $this->assertLessThanOrEqual(23, $diff);
    }

    public function test_settings_update_denied_without_permission(): void
    {
        $this->actingAs($this->workerUser);
        // settings.manage — не у роли employee
        $this->post('/settings', ['settings' => ['corpms.signature.otp_ttl' => 99]])->assertForbidden();
    }

    public function test_settings_view_only_for_employee_without_manage(): void
    {
        $this->actingAs($this->workerUser);
        // у employee нет settings.view / settings.manage — 403
        $this->get('/settings')->assertForbidden();
    }

    /* -------------------- дашборд -------------------- */

    public function test_dashboard_shows_role_based_cards(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'clock_in_at' => now()->subHours(2),
            'late_minutes' => 0,
            'status' => AttendanceStatus::OnTime,
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Обзор')
            ->assertSee('Посещаемость сегодня');
    }
}
