<?php

namespace Tests\Feature;

use App\Domains\Ai\Jobs\RetryAiAnswer;
use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function failedJob(): AiConversation
    {
        $this->seed(RbacSeeder::class);
        $company = Company::create(['name' => 'Recovery', 'code' => 'recovery']);
        $user = User::factory()->create();
        $employee = Employee::create(['company_id' => $company->id, 'user_id' => $user->id, 'employee_no' => 'R-1',
            'first_name' => 'Test', 'last_name' => 'Recovery', 'status' => 'active']);
        $employee->roles()->attach(Role::where('slug', 'super_admin')->value('id'));
        $conversation = AiConversation::create(['employee_id' => $employee->id, 'question' => 'Сколько сотрудников?',
            'answer' => 'Локальный ответ', 'grounded' => false]);
        DB::table('failed_jobs')->insert(['uuid' => 'recovery-test', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => RetryAiAnswer::class, 'data' => ['command' => serialize(new RetryAiAnswer($conversation->id))]]),
            'exception' => 'Provider was unavailable', 'failed_at' => now()]);
        config(['corpms.ai.base_url' => 'https://recovery.example/v1', 'corpms.ai.api_key' => 'fixture-key']);
        Storage::fake('local');

        return $conversation;
    }

    public function test_recovery_archives_failure_before_removing_it_and_updates_answer(): void
    {
        $conversation = $this->failedJob();
        Http::fake(['recovery.example/*' => Http::response(['choices' => [['message' => ['content' => 'Ответ восстановлен']]]])]);
        $this->artisan('corpms:ai-recover')->assertExitCode(0);
        $this->assertTrue($conversation->fresh()->grounded);
        $this->assertDatabaseCount('failed_jobs', 0);
        $files = Storage::disk('local')->files('queue-recovery');
        $this->assertCount(1, $files);
        $archive = json_decode(Crypt::decryptString(Storage::disk('local')->get($files[0])), true);
        $this->assertSame('Provider was unavailable', $archive['original']['exception']);
        $this->assertSame('recovered', $archive['outcome']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.queue_resolved']);
    }

    public function test_provider_failure_keeps_original_job(): void
    {
        $this->failedJob();
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->artisan('corpms:ai-recover')->assertExitCode(1);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_dry_run_does_not_contact_provider_or_remove_jobs(): void
    {
        $this->failedJob();
        Http::fake();
        $this->artisan('corpms:ai-recover', ['--dry-run' => true])->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertDatabaseCount('failed_jobs', 1);
    }
}
