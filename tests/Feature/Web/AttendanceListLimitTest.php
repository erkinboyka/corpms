<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DB-10: unbounded get() в списке посещаемости и live-фиде. */
class AttendanceListLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_feed_limits_rows_but_counts_all_records(): void
    {
        $this->seed(RbacSeeder::class);
        $company = Company::create(['name' => 'Limit Co', 'code' => 'limitco']);
        $adminUser = User::factory()->create();
        $admin = Employee::create([
            'company_id' => $company->id,
            'user_id' => $adminUser->id,
            'employee_no' => 'LIM-ADM',
            'first_name' => 'Админ',
            'last_name' => 'Табеля',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $admin->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $employee = Employee::create([
                'company_id' => $company->id,
                'user_id' => User::factory()->create()->id,
                'employee_no' => 'LIM-'.$i,
                'first_name' => 'Сотрудник',
                'last_name' => (string) $i,
                'status' => 'active',
            ]);
            $rows[] = [
                'employee_id' => $employee->id,
                'date' => today()->toDateString(),
                'clock_in_at' => today()->toDateString().' 09:00:00',
                'status' => 'present',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        AttendanceRecord::query()->insert($rows);

        $response = $this->actingAs($adminUser)->getJson('/attendance/live/feed')->assertOk();

        $this->assertCount(100, $response->json('records'));
        $this->assertSame(101, array_sum($response->json('counters')));
    }
}
