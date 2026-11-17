<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\DocumentCategory;
use App\Models\DocumentTemplate;
use App\Models\DocumentWorkflow;
use App\Models\DocumentWorkflowStep;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Закрытие непокрытых прав ТЗ: workflows.view/manage, documents.templates (view+manage),
 * documents.categories.manage, users.manage, biometry.events.view, biometry.privacy.manage,
 * integrations.manage.
 */
class AdminModulesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected WorkSchedule $schedule;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Admin Co', 'code' => 'admco']);
        $this->schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_start' => '13:00:00',
            'break_end' => '14:00:00',
            'grace_minutes' => 10,
        ]);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Анна', 'Апгрейд', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->worker = $this->employee('W-1', 'Виктор', 'Исполнитель');
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null, int $schedule = 0): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'work_schedule_id' => $schedule === 0 ? $this->schedule->id : $schedule,
        ]);
    }

    /* -------------------- workflows (workflows.view / workflows.manage) -------------------- */

    public function test_workflow_page_and_linear_workflow_creation(): void
    {
        $this->get('/workflows')->assertOk()->assertSee('Маршруты согласования');

        $roleCeo = Role::query()->where('slug', 'company_director')->value('id');

        $this->post('/workflows', [
            'name' => 'Приказы — стандартный маршрут',
            'applicable_categories' => 'hr, admin',
            'steps' => [
                ['name' => 'Начальник отдела', 'actor_type' => 'role', 'role_id' => Role::query()->where('slug', 'department_director')->value('id'), 'required_action' => 'review'],
                ['name' => 'Директор', 'actor_type' => 'role', 'role_id' => $roleCeo, 'required_action' => 'approve', 'is_signature_step' => '1'],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $workflow = DocumentWorkflow::query()->where('name', 'Приказы — стандартный маршрут')->firstOrFail();
        self::assertSame(['hr', 'admin'], $workflow->applicable_categories);

        /** @var DocumentWorkflowStep[] $steps */
        $steps = $workflow->steps()->orderBy('step_order')->get();
        self::assertCount(2, $steps);
        self::assertSame(1, $steps[0]->step_order);
        self::assertFalse($steps[0]->is_terminal);
        self::assertTrue($steps[1]->is_terminal);
        self::assertTrue($steps[1]->is_signature_step);

        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.created']);

        // шагов больше 8 нельзя
        $many = [];
        for ($i = 0; $i < 10; $i++) {
            $many[] = ['name' => 'Шаг '.$i, 'actor_type' => 'author', 'required_action' => 'review'];
        }
        $this->post('/workflows', ['name' => 'Длинный', 'steps' => $many])->assertSessionHasErrors(['steps']);
    }

    public function test_workflows_view_denied_without_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $bare = $this->employee('B-9', 'Борис', 'Безроли');
        $user = User::query()->whereKey($bare->user_id)->firstOrFail();
        $this->actingAs($user);
        $this->get('/workflows')->assertForbidden();
    }

    /* -------------------- шаблоны и категории -------------------- */

    public function test_category_and_template_and_document_generation(): void
    {
        $this->get('/documents/templates')->assertOk()->assertSee('Шаблоны документов');

        // категория
        $this->post('/documents/templates/category', [
            'code' => 'hr-orders',
            'name' => 'Приказы по персоналу',
        ])->assertRedirect()->assertSessionHas('status');

        $category = DocumentCategory::query()->where('code', 'hr-orders')->firstOrFail();
        self::assertSame('Приказы по персоналу', $category->name);

        // шаблон с переменными
        $this->post('/documents/templates', [
            'name' => 'Шаблон отпуска',
            'category_id' => $category->id,
            'kind' => 'blade',
            'body' => 'Заявление сотрудника {{ $employee_name }} на период {{ $vacation_period }} от {{ $today_date }}',
            'variables' => 'employee_name, vacation_period',
        ])->assertRedirect()->assertSessionHas('status');

        $template = DocumentTemplate::query()->where('name', 'Шаблон отпуска')->firstOrFail();
        self::assertSame(['employee_name', 'vacation_period'], $template->variables);

        // генерация документа из шаблона
        $this->post('/documents/templates/'.$template->id.'/create', [
            'title' => 'Заявление на отпуск',
            'variables' => [
                'employee_name' => 'Иванов И.',
                'vacation_period' => 'с 01.07 по 14.07',
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $this->get('/documents/mine')->assertOk()->assertSee('Заявление на отпуск');

        // отсутствующая переменная — ошибка
        $this->post('/documents/templates/'.$template->id.'/create', [
            'variables' => ['employee_name' => 'Иванов И.'],
        ])->assertRedirect()->assertSessionHasErrors(['variables']);

        // дубликат кода категории
        $this->post('/documents/templates/category', [
            'code' => 'hr-orders',
            'name' => 'Второй',
        ])->assertSessionHasErrors(['code']);
    }

    /* -------------------- учётные записи (users.manage) -------------------- */

    public function test_users_panel_create_account_and_reset_password(): void
    {
        $this->get('/users')->assertOk()->assertSee('Учётные записи');

        // сотрудник без учётки — кандидат на привязку
        $candidate = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => null,
            'employee_no' => 'W-5',
            'first_name' => 'Влад',
            'last_name' => 'Безучётки',
            'status' => 'active',
            'work_schedule_id' => $this->schedule->id,
        ]);

        $this->post('/users', [
            'name' => 'Новая учётка',
            'email' => 'new.acct@corpms.demo',
            'employee_id' => $candidate->id,
        ])->assertRedirect()->assertSessionHas('status');

        $user = User::query()->where('email', 'new.acct@corpms.demo')->firstOrFail();
        $candidate->refresh();
        self::assertSame($user->id, $candidate->user_id);

        $oldHash = $user->password;
        $this->post('/users/'.$user->id.'/password', [
            'password' => 'super-secret-9',
        ])->assertRedirect();

        $user->refresh();
        self::assertNotSame($oldHash, $user->password);
        self::assertNotSame('super-secret-9', $user->password); // хеш, а не открытый текст

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created']);
    }

    public function test_users_panel_denied_for_common_employee(): void
    {
        $this->actingAs(User::query()->whereKey($this->worker->user_id)->firstOrFail());
        $this->get('/users')->assertForbidden();
        $this->post('/users', ['name' => 'x', 'email' => 'x@corpms.demo'])->assertForbidden();
    }

    /* -------------------- журнал биособытий (biometry.events.view) -------------------- */

    public function test_biometric_events_journal_with_scope(): void
    {
        $device = BiometricDevice::create([
            'company_id' => $this->company->id,
            'name' => 'Пристеничный Т1',
            'device_type' => 'face_terminal',
            'vendor' => 'generic',
            'serial_number' => 'SN-ADMIN-1',
            'api_token_hash' => 'hash',
        ]);

        BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $this->worker->id,
            'event_uuid' => 'uuid-evt-1',
            'occurred_at' => now()->subMinutes(5),
            'direction' => 'in',
            'liveness_passed' => true,
            'confidence' => 0.9912,
            'processing_status' => 'processed',
            'raw_payload' => ['x' => 1],
        ]);

        // админ (scope company) видит события
        $this->get('/biometrics/events')
            ->assertOk()
            ->assertSee('Исполнитель')
            ->assertSee('Пристеничный Т1')
            ->assertSee('вход');

        // фильтр по устройству
        $this->get('/biometrics/events?device_id='.$device->id)->assertOk()->assertSee('Исполнитель');

        // сотрудник-localhost со scope own: BiometricEvent привязан к его employee_id
        $this->actingAs(User::query()->whereKey($this->worker->user_id)->firstOrFail());
        // employee не имеет biometry.events.view → 403
        $this->get('/biometrics/events')->assertForbidden();
    }

    public function test_biometric_events_denied_without_permission(): void
    {
        $this->actingAs(User::query()->whereKey($this->worker->user_id)->firstOrFail());
        $this->get('/biometrics/events')->assertForbidden();
    }

    /* -------------------- приватность биометрии (biometry.privacy.manage) -------------------- */

    public function test_biometric_privacy_saves_retention_and_purges(): void
    {
        $device = BiometricDevice::create([
            'company_id' => $this->company->id,
            'name' => 'Т2',
            'device_type' => 'face_terminal',
            'vendor' => 'generic',
            'serial_number' => 'SN-ADMIN-2',
            'api_token_hash' => 'hash',
        ]);

        // старое событие с кадром (просрочено) + свежее с кадром
        Storage::put('biometric/old.jpg', 'old');
        Storage::put('biometric/fresh.jpg', 'fresh');

        BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $this->worker->id,
            'event_uuid' => 'uuid-priv-1',
            'occurred_at' => now()->subDays(100),
            'direction' => 'in',
            'processing_status' => 'processed',
            'image_ref' => 'biometric/old.jpg',
            'raw_payload' => [],
        ]);
        BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $this->worker->id,
            'event_uuid' => 'uuid-priv-2',
            'occurred_at' => now()->subDays(2),
            'direction' => 'out',
            'processing_status' => 'processed',
            'image_ref' => 'biometric/fresh.jpg',
            'raw_payload' => [],
        ]);

        $this->post('/biometrics/privacy', [
            'require_liveness' => '1',
            'image_retention_days' => 30,
            'event_retention_days' => 365,
        ])->assertRedirect()->assertSessionHas('status');

        self::assertSame('30', Setting::query()->where('key', 'biometric.image_retention_days')->value('value'));
        self::assertSame('1', Setting::query()->where('key', 'biometric.require_liveness')->value('value'));

        // форма с очисткой
        $this->post('/biometrics/privacy', [
            'image_retention_days' => 30,
            'event_retention_days' => 365,
            'purge_now' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', ['action' => 'biometry.privacy.updated']);

        self::assertNull(BiometricEvent::query()->where('event_uuid', 'uuid-priv-1')->value('image_ref'));
        Storage::disk('local')->assertMissing('biometric/old.jpg');
        Storage::disk('local')->assertExists('biometric/fresh.jpg');
        self::assertNotNull(BiometricEvent::query()->where('event_uuid', 'uuid-priv-2')->value('image_ref'));

        // страница показывает параметры
        $this->get('/biometrics/privacy')->assertOk()->assertSee('Приватность биометрии');
    }

    public function test_biometric_privacy_denied_without_permission(): void
    {
        $this->actingAs(User::query()->whereKey($this->worker->user_id)->firstOrFail());
        $this->get('/biometrics/privacy')->assertForbidden();
        $this->post('/biometrics/privacy', ['image_retention_days' => 30, 'event_retention_days' => 365])->assertForbidden();
    }

    /* -------------------- интеграции (integrations.manage) -------------------- */

    public function test_integrations_read_only_page(): void
    {
        $this->get('/integrations')
            ->assertOk()
            ->assertSee('Интеграции')
            ->assertSee('Telegram');

        // страницу нельзя править: только GET-маршрут
        $this->post('/integrations')->assertStatus(405);
    }

    public function test_integrations_denied_without_permission(): void
    {
        $this->actingAs(User::query()->whereKey($this->worker->user_id)->firstOrFail());
        $this->get('/integrations')->assertForbidden();
    }
}
