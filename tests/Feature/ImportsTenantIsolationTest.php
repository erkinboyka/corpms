<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\ImportJob;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P1 SEC-03/BE-01: список и операции импорта не должны пересекать арендаторов. */
class ImportsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    /** @return array{User, Company} */
    private function tenant(string $code): array
    {
        $company = Company::create(['name' => 'Import '.$code, 'code' => $code]);
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => $code.'-1',
            'first_name' => 'Импорт',
            'last_name' => $code,
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        return [$user, $company];
    }

    private function job(Company $company, string $path): ImportJob
    {
        return ImportJob::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'type' => 'employees',
            'file_path' => $path,
            'status' => 'pending',
        ]);
    }

    public function test_import_list_is_scoped_to_current_company(): void
    {
        [$userA, $companyA] = $this->tenant('importa');
        [, $companyB] = $this->tenant('importb');
        $foreign = $this->job($companyB, 'imports/foreign.csv');
        $own = $this->job($companyA, 'imports/own.csv');

        $this->actingAs($userA)
            ->get('/imports')
            ->assertOk()
            ->assertViewHas('jobs', fn ($jobs) => $jobs->contains('id', $own->id))
            ->assertViewHas('jobs', fn ($jobs) => ! $jobs->contains('id', $foreign->id));
    }

    public function test_import_show_and_run_reject_foreign_job(): void
    {
        [$userA] = $this->tenant('importc');
        [, $companyB] = $this->tenant('importd');
        $foreign = $this->job($companyB, 'imports/foreign-2.csv');

        $this->actingAs($userA)
            ->get('/imports/'.$foreign->id)
            ->assertForbidden();

        $this->actingAs($userA)
            ->post('/imports/'.$foreign->id.'/run')
            ->assertForbidden();
    }
}
