<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ТЗ п.16–19: рабочие дни недели — явный реквизит графика. */
class ScheduleWorkDaysHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected WorkSchedule $schedule;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Schedule Co', 'code' => 'scheduleco']);
        $this->schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'Пн–Пт',
            'type' => 'standard',
            'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);

        $this->user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'employee_no' => 'S-1',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'status' => 'active',
            'work_schedule_id' => $this->schedule->id,
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'company_director')->value('id'),
        ]);

        $this->actingAs($this->user);
    }

    public function test_schedule_page_shows_all_seven_weekdays(): void
    {
        $this->get('/schedules')
            ->assertOk()
            ->assertSee('Рабочие дни недели')
            ->assertSee('name="work_days[]"', false)
            ->assertSee('value="7"', false)
            ->assertSee('Пн–Пт', false);
    }

    public function test_store_normalizes_and_persists_working_days(): void
    {
        $this->post('/schedules', [
            'name' => 'Смена с субботой',
            'type' => 'standard',
            'work_days' => [6, 1, 7],
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'grace_minutes' => 10,
        ])->assertRedirect()->assertSessionHas('status');

        $created = WorkSchedule::query()->where('name', 'Смена с субботой')->sole();
        self::assertSame([1, 6, 7], $created->workingDays());
    }

    public function test_update_replaces_working_days(): void
    {
        $this->patch('/schedules/'.$this->schedule->id, [
            'name' => 'Пн/Ср/Пт',
            'type' => 'standard',
            'work_days' => [5, 3, 1],
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
        ])->assertRedirect()->assertSessionHas('status');

        self::assertSame([1, 3, 5], $this->schedule->fresh()->workingDays());
    }

    public function test_store_without_work_days_keeps_weekday_default(): void
    {
        $this->post('/schedules', [
            'name' => 'Старый клиент',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ])->assertRedirect()->assertSessionHas('status');

        self::assertSame([1, 2, 3, 4, 5], WorkSchedule::query()->where('name', 'Старый клиент')->sole()->workingDays());
    }

    public function test_update_without_work_days_preserves_existing_days(): void
    {
        $this->schedule->update(['work_days' => [2, 4, 6]]);

        $this->patch('/schedules/'.$this->schedule->id, [
            'name' => 'Пн–Пт',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ])->assertRedirect()->assertSessionHas('status');

        self::assertSame([2, 4, 6], $this->schedule->fresh()->workingDays());
    }

    public function test_at_least_one_working_day_is_required(): void
    {
        $this->post('/schedules', [
            'name' => 'Пустой график',
            'type' => 'standard',
            'work_days' => [],
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ])->assertSessionHasErrors('work_days');
    }
}
