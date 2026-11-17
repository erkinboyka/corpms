<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Демо-данные: компания, отделы, директора, сотрудники, графики (п.68 ТЗ).
 * Логины: <role>@corpms.demo, пароль 'password'.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::create([
            'name' => 'Demo Company',
            'code' => 'demo',
            'legal_name' => 'ООО «Демо Компани»',
            'email' => 'info@demo.company',
            'phone' => '+992 90 000 00 00',
        ]);

        $position = static function (string $level) use ($company): int {
            return Position::query()->where('company_id', $company->id)->where('level', $level)->value('id');
        };

        $schedule = WorkSchedule::create([
            'company_id' => $company->id,
            'name' => 'Стандартный 08:00–17:00',
            'type' => 'standard',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
            'grace_minutes' => 10,
        ]);

        $positionIds = [
            1 => Position::create(['company_id' => $company->id, 'name' => 'Директор компании', 'code' => 'ceo', 'level' => 1])->id,
            2 => Position::create(['company_id' => $company->id, 'name' => 'Директор отдела', 'code' => 'dept-dir', 'level' => 2])->id,
            3 => Position::create(['company_id' => $company->id, 'name' => 'Заместитель директора', 'code' => 'deputy', 'level' => 2])->id,
            10 => Position::create(['company_id' => $company->id, 'name' => 'Ведущий специалист', 'code' => 'senior', 'level' => 3])->id,
            11 => Position::create(['company_id' => $company->id, 'name' => 'Специалист', 'code' => 'specialist', 'level' => 4])->id,
        ];

        // --- CEO ---
        $ceo = $this->makeEmployee($company, [
            'first_name' => 'Рахим', 'last_name' => 'Каримов', 'middle_name' => 'Абдулович',
            'email' => 'ceo@corpms.demo', 'department_id' => null,
            'position_id' => $positionIds[1], 'position' => 'Директор компании', 'role' => 'company_director',
            'manager_id' => null, 'employee_no' => '0001',
        ], $schedule->id);

        // --- Отделы ---
        $departments = [
            ['code' => 'it', 'name' => 'IT-отдел'],
            ['code' => 'hr', 'name' => 'Отдел по работе с персоналом'],
            ['code' => 'fin', 'name' => 'Финансовый отдел'],
            ['code' => 'leg', 'name' => 'Юридический отдел'],
            ['code' => 'adm', 'name' => 'Административный отдел'],
        ];

        foreach ($departments as $i => $d) {
            $dept = Department::create([
                'company_id' => $company->id,
                'code' => $d['code'],
                'name' => $d['name'],
                'description' => 'Демонстрационный отдел',
            ]);

            $positionsByDept = [
                'it' => [['Backend Developer', $positionIds[11]], ['Frontend Developer', $positionIds[11]], ['System Administrator', $positionIds[11]], ['Support Engineer', $positionIds[11]], ['Backend Developer Junior', $positionIds[11]]],
                'hr' => [['HR Manager', $positionIds[10]], ['HR Specialist', $positionIds[11]], ['Recruiter', $positionIds[11]]],
                'fin' => [['Бухгалтер', $positionIds[10]], ['Бухгалтер-калькулятор', $positionIds[11]], ['Казначей', $positionIds[11]]],
                'leg' => [['Юрист', $positionIds[10]], ['Юрист-консультант', $positionIds[11]]],
                'adm' => [['Офис-менеджер', $positionIds[10]], ['Курьер', $positionIds[11]]],
            ];

            // директор отдела
            $deptDirector = $this->makeEmployee($company, [
                'first_name' => 'Фарру', 'last_name' => 'Ахмов'.$i, 'middle_name' => 'Расулович',
                'email' => $d['code'].'-director@corpms.demo',
                'department_id' => $dept->id,
                'position_id' => $positionIds[2],
                'position' => 'Директор '.$d['name'],
                'role' => $d['code'] === 'hr' ? ['department_director', 'hr_manager'] : 'department_director',
                'manager_id' => $ceo->id,
                'employee_no' => '100'.($i + 1),
                'joint' => $d['name'],
            ], $schedule->id);

            $dept->update(['director_id' => $deptDirector->id]);

            // сотрудники отдела (итого ≈ 30+)
            foreach ($positionsByDept[$d['code']] as $j => [$title, $posId]) {
                $slug = preg_replace('/[^a-z]+/', '', strtolower($title)) ?: 'emp';
                $this->makeEmployee($company, [
                    'first_name' => 'Сотрудник '.($j + 1), 'last_name' => $d['code'].'ов', 'middle_name' => null,
                    'email' => $d['code'].'-emp'.($j + 1).'@corpms.demo',
                    'department_id' => $dept->id,
                    'position_id' => $posId,
                    'position' => $title,
                    'role' => 'employee',
                    'manager_id' => $deptDirector->id,
                    'employee_no' => '200'.$i.($j + 1),
                ], $schedule->id);
            }
        }

        // super admin
        User::create([
            'name' => 'Super Admin',
            'email' => 'admin@corpms.demo',
            'password' => Hash::make('password'),
        ])->employee()->create([
            'company_id' => $company->id,
            'employee_no' => '9001',
            'last_name' => 'Admin', 'first_name' => 'Super',
            'department_id' => null,
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
        ])->roles()->attach(Role::where('slug', 'super_admin')->value('id'));
    }

    private function makeEmployee(Company $company, array $data, int $scheduleId): Employee
    {
        $user = User::create([
            'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
            'email' => $data['email'],
            'password' => Hash::make('password'),
        ]);

        $roleSlugs = is_array($data['role']) ? $data['role'] : [$data['role']];

        $employee = $user->employee()->create([
            'company_id' => $company->id,
            'employee_no' => $data['employee_no'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'email' => $data['email'],
            'department_id' => $data['department_id'],
            'position_id' => $data['position_id'],
            'manager_id' => $data['manager_id'],
            'hired_at' => now()->subMonths(random_int(1, 24))->toDateString(),
            'status' => 'active',
            'work_schedule_id' => $scheduleId,
        ]);

        $roleIds = Role::query()->whereIn('slug', $roleSlugs)->pluck('id');
        $employee->roles()->sync($roleIds);

        return $employee;
    }
}
