<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-01 regression: backups contain the whole installation database, so a
 * company-scoped role must never be able to list, create or download them.
 */
class BackupsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->company = Company::create(['name' => 'Backup tenant', 'code' => 'backup-tenant']);

        $this->backupDir = storage_path('framework/testing/backups-'.uniqid('', true));
        mkdir($this->backupDir, 0777, true);
        config(['backup.path' => $this->backupDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            foreach (scandir($this->backupDir) ?: [] as $name) {
                if ($name !== '.' && $name !== '..' && is_file($this->backupDir.DIRECTORY_SEPARATOR.$name)) {
                    unlink($this->backupDir.DIRECTORY_SEPARATOR.$name);
                }
            }
            rmdir($this->backupDir);
        }

        parent::tearDown();
    }

    private function employee(string $role): Employee
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_no' => 'B-'.$user->id,
            'first_name' => 'Backup',
            'last_name' => 'User'.$user->id,
            'status' => 'active',
        ]);
        $employee->roles()->attach(Role::where('slug', $role)->value('id'));

        return $employee;
    }

    public function test_company_scoped_role_cannot_list_create_or_download_backups(): void
    {
        // company_director has settings.view@company and settings.manage@company —
        // the exact permissions that used to guard these routes.
        $director = $this->employee('company_director');

        $this->actingAs($director->user);
        $this->get(route('backups.index'))->assertForbidden();
        $this->post(route('backups.db'))->assertForbidden();
        $this->post(route('backups.files'))->assertForbidden();
        $this->get(route('backups.download', 'db.sql.gz'))->assertForbidden();
    }

    public function test_platform_admin_can_list_and_download_and_download_is_audited(): void
    {
        $admin = $this->employee('super_admin');
        file_put_contents($this->backupDir.DIRECTORY_SEPARATOR.'db.sql.gz', 'fake-backup');

        $this->actingAs($admin->user);
        $this->get(route('backups.index'))->assertOk()->assertSee('db.sql.gz');
        $this->get(route('backups.download', 'db.sql.gz'))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->user_id,
            'action' => 'backup.downloaded',
            'object_type' => 'backup',
        ]);
        $this->assertSame('db.sql.gz', AuditLog::query()->where('action', 'backup.downloaded')->sole()->new_value['file']);
    }

    public function test_download_cannot_escape_backup_directory(): void
    {
        $admin = $this->employee('super_admin');
        $outside = storage_path('framework/testing/outside-secret-'.uniqid().'.txt');
        file_put_contents($outside, 'secret');
        file_put_contents($this->backupDir.DIRECTORY_SEPARATOR.'db.sql.gz', 'fake-backup');

        try {
            $this->actingAs($admin->user);
            $this->get(route('backups.download', '..%2F'.basename($outside)))->assertNotFound();
            $this->get(route('backups.download', 'missing.sql.gz'))->assertNotFound();
        } finally {
            if (is_file($outside)) {
                unlink($outside);
            }
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('backups.index'))->assertRedirect(route('login'));
    }
}
