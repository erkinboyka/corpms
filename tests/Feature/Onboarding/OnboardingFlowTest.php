<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Onboarding\Services\OnboardingOrchestrator;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\OnboardingStep;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\EmployeeStatus;
use App\Shared\Enums\LmsCourseStatus;
use App\Shared\Enums\OnboardingFlowStatus;
use App\Shared\Enums\OnboardingStepStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ONB-01: оркестратор найма должен быть идемпотентным, уметь переживать отказ
 * шага и отзывать только те роли, которые сам выдал.
 */
class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $adminEmployee;

    protected Employee $hire;

    protected LmsCourse $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create([
            'name' => 'Hiring Co',
            'code' => 'hiringco',
            'onboarding_completed_at' => now(),
        ]);

        $this->admin = User::factory()->create(['email' => 'onb-admin@corpms.demo']);
        $this->adminEmployee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'employee_no' => 'ONB-A',
            'first_name' => 'Админ',
            'last_name' => 'Найма',
            'status' => EmployeeStatus::Active,
        ]);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $hireUser = User::factory()->create(['email' => 'onb-hire@corpms.demo']);
        $this->hire = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $hireUser->id,
            'employee_no' => 'ONB-H-1',
            'first_name' => 'Новый',
            'last_name' => 'Сотрудник',
            'status' => EmployeeStatus::Active,
            'hired_at' => now()->toDateString(),
        ]);

        DocumentCategory::create(['code' => 'onboarding', 'name' => 'Онбординг', 'is_active' => true]);

        $this->course = LmsCourse::create([
            'company_id' => $this->company->id,
            'title' => 'Курс адаптации',
            'status' => LmsCourseStatus::Published,
            'published_at' => now(),
            'created_by' => $this->adminEmployee->id,
        ]);

        $this->actingAs($this->admin);
    }

    /** Повторный start/run не дублирует документы, обучение, задачи и роли; revoke снимает только свои роли. */
    public function test_repeated_events_are_idempotent_and_revoke_removes_only_flow_roles(): void
    {
        $orchestrator = app(OnboardingOrchestrator::class);
        $options = ['course_ids' => [$this->course->id]];

        $flow = $orchestrator->start($this->hire, $this->admin, $options);
        $again = $orchestrator->start($this->hire, $this->admin, $options);

        self::assertSame($flow->id, $again->id);
        self::assertSame(5, OnboardingStep::query()->where('flow_id', $flow->id)->count());

        $flow = $orchestrator->run($flow, $this->admin);

        self::assertSame(OnboardingFlowStatus::Completed, $flow->fresh()->status);
        self::assertSame(5, OnboardingStep::query()->where('flow_id', $flow->id)->where('status', OnboardingStepStatus::Completed)->count());
        self::assertSame(1, Document::query()->where('company_id', $this->company->id)->count());
        self::assertSame(1, LmsEnrollment::query()->where('employee_id', $this->hire->id)->count());
        self::assertSame(1, Task::query()->where('company_id', $this->company->id)->where('title', 'like', 'Онбординг #%')->count());
        self::assertTrue($this->hasEmployeeRole($this->hire, 'employee'));

        // повтор события не создаёт вторые сущности
        $orchestrator->run($flow, $this->admin);

        self::assertSame(1, Document::query()->where('company_id', $this->company->id)->count());
        self::assertSame(1, LmsEnrollment::query()->where('employee_id', $this->hire->id)->count());
        self::assertSame(1, Task::query()->where('company_id', $this->company->id)->where('title', 'like', 'Онбординг #%')->count());
        self::assertSame(1, EmployeeRole::query()->where('employee_id', $this->hire->id)->count());

        // отзыв снимает только роль, выданную этим потоком
        $orchestrator->revoke($flow, $this->admin);
        $orchestrator->revoke($flow, $this->admin);

        self::assertSame(OnboardingFlowStatus::Cancelled, $flow->fresh()->status);
        self::assertFalse($this->hasEmployeeRole($this->hire, 'employee'));
    }

    /** Отказ шага access фиксируется, повтор previous-шагов не дублируется, retry завершает поток. */
    public function test_failed_step_can_be_retried_without_repeating_earlier_side_effects(): void
    {
        config()->set('corpms.onboarding.roles', ['missing_role']);

        $otherUser = User::factory()->create(['email' => 'onb-hire2@corpms.demo']);
        $hire = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $otherUser->id,
            'employee_no' => 'ONB-H-2',
            'first_name' => 'Второй',
            'last_name' => 'Новый',
            'status' => EmployeeStatus::Active,
        ]);

        $orchestrator = app(OnboardingOrchestrator::class);
        $flow = $orchestrator->start($hire, $this->admin, ['course_ids' => [$this->course->id]]);
        $flow = $orchestrator->run($flow, $this->admin);

        self::assertSame(OnboardingFlowStatus::Failed, $flow->fresh()->status);
        $access = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'access')->firstOrFail();
        self::assertSame(OnboardingStepStatus::Failed, $access->status);
        self::assertNotNull($access->error);
        self::assertFalse($this->hasEmployeeRole($hire, 'employee'));
        self::assertSame(1, Document::query()->where('company_id', $this->company->id)->count());
        self::assertSame(1, LmsEnrollment::query()->where('employee_id', $hire->id)->count());

        // оператор исправляет выбор ролей и повторяет только упавший шаг
        $flow = $orchestrator->retry($flow, 'access', $this->admin, ['role_slugs' => ['employee']]);

        self::assertSame(OnboardingFlowStatus::Completed, $flow->fresh()->status);
        self::assertTrue($this->hasEmployeeRole($hire, 'employee'));
        self::assertSame(1, Document::query()->where('company_id', $this->company->id)->count());
        self::assertSame(1, LmsEnrollment::query()->where('employee_id', $hire->id)->count());
        self::assertSame(1, Task::query()->where('company_id', $this->company->id)->count());
    }

    /** HTTP-слой не даёт запустить поток по чужому сотруднику и не пускает без onboarding.manage. */
    public function test_http_routes_are_tenant_scoped_and_permission_gated(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Hiring',
            'code' => 'otherhiring',
            'onboarding_completed_at' => now(),
        ]);
        $foreignUser = User::factory()->create(['email' => 'foreign-onb@corpms.demo']);
        $foreignEmployee = Employee::create([
            'company_id' => $otherCompany->id,
            'user_id' => $foreignUser->id,
            'employee_no' => 'FOREIGN-1',
            'first_name' => 'Чужой',
            'last_name' => 'Сотрудник',
            'status' => EmployeeStatus::Active,
        ]);

        $this->get('/onboarding/flows')->assertOk();
        $this->post('/onboarding/flows', ['employee_id' => $foreignEmployee->id])->assertForbidden();

        $flow = app(OnboardingOrchestrator::class)->start($this->hire, $this->admin, []);
        $this->get('/onboarding/flows/'.$flow->id)->assertOk();

        $plainUser = User::factory()->create(['email' => 'plain-onb@corpms.demo']);
        $plainEmployee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $plainUser->id,
            'employee_no' => 'ONB-P',
            'first_name' => 'Обычный',
            'last_name' => 'Сотрудник',
            'status' => EmployeeStatus::Active,
        ]);
        EmployeeRole::create([
            'employee_id' => $plainEmployee->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($plainUser)
            ->post('/onboarding/flows', ['employee_id' => $this->hire->id])
            ->assertForbidden();
    }

    private function hasEmployeeRole(Employee $employee, string $slug): bool
    {
        return EmployeeRole::query()
            ->where('employee_id', $employee->id)
            ->where('role_id', Role::query()->where('slug', $slug)->value('id'))
            ->exists();
    }
}
