<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounting\Jobs\SyncFinanceEntries;
use App\Domains\Backup\Jobs\RunDatabaseBackup;
use App\Domains\Backup\Jobs\RunFilesBackup;
use App\Domains\Bi\Jobs\RebuildBiMart;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Support\SingleFlightLock;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** P1 BE-06/PERF-06: тяжёлые операции уходят в очередь и защищены от шторма. */
class HeavyOperationsQueueTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Queue Co', 'code' => 'queueco']);
        $this->admin = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'employee_no' => 'Q-1',
            'first_name' => 'Очередь',
            'last_name' => 'Админ',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        Cache::flush();
        foreach (['accounting.sync', 'bi.rebuild', 'backups.db', 'backups.files'] as $prefix) {
            RateLimiter::clear($prefix.sha1((string) $this->admin->id));
        }

        Queue::fake();
        $this->actingAs($this->admin);
    }

    public function test_heavy_operations_are_dispatched_to_queue(): void
    {
        $month = now()->format('Y-m');

        $this->post('/accounting/sync', ['month' => $month])->assertSessionHasNoErrors();
        $this->post('/bi/rebuild', ['month' => $month])->assertSessionHasNoErrors();
        $this->post(route('backups.db'))->assertRedirect(route('backups.index'));
        $this->post(route('backups.files'))->assertRedirect(route('backups.index'));

        Queue::assertPushed(SyncFinanceEntries::class);
        Queue::assertPushed(RebuildBiMart::class);
        Queue::assertPushed(RunDatabaseBackup::class);
        Queue::assertPushed(RunFilesBackup::class);
    }

    public function test_second_request_within_minute_is_throttled(): void
    {
        $month = now()->format('Y-m');

        $this->post('/accounting/sync', ['month' => $month])->assertSessionHasNoErrors();
        $this->post('/accounting/sync', ['month' => $month])->assertStatus(429);
    }

    public function test_active_single_flight_lock_returns_429(): void
    {
        $key = 'accounting.sync:'.$this->company->id;
        $token = SingleFlightLock::acquire($key);
        $this->assertIsString($token);

        try {
            $this->post('/accounting/sync', ['month' => now()->format('Y-m')])
                ->assertStatus(429);
        } finally {
            SingleFlightLock::release($key, $token);
        }
    }
}
