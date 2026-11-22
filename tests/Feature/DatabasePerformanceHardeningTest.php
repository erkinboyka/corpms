<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Chat\Services\ChatService;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P2 (DB-S1…S5 / PERF-07/09/10): период отчёта ограничен, аудит имеетtenant-ключ
 * и индекс, дублирующий unique settings удалён, граница прочитанного чата
 * проверяется на принадлежность диалогу.
 */
final class DatabasePerformanceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $company = Company::create(['name' => 'Perf Hardening Co', 'code' => 'perfhard']);
        $user = User::factory()->create();
        $this->employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'P-1',
            'first_name' => 'Пётр',
            'last_name' => 'Производственный',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'P-2',
            'first_name' => 'Мария',
            'last_name' => 'Собеседник',
            'status' => 'active',
        ]);
        $this->actingAs($user);
    }

    public function test_two_century_report_range_is_rejected(): void
    {
        $response = $this->getJson('/reports/attendance?from=1900-01-01&to=2100-01-01');

        $response->assertStatus(422);
    }

    /** TEST-11: матрица дат периода отчёта через data provider. */
    #[DataProvider('reportPeriodMatrix')]
    public function test_report_period_validation_matrix(string $from, string $to, int $status): void
    {
        $this->getJson('/reports/attendance?from='.$from.'&to='.$to)->assertStatus($status);
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function reportPeriodMatrix(): array
    {
        $today = now()->toDateString();

        return [
            'one day' => [$today, $today, 200],
            'one year' => [now()->subYear()->toDateString(), $today, 200],
            'two years' => [now()->subYears(2)->toDateString(), $today, 422],
            'two centuries' => ['1900-01-01', '2100-01-01', 422],
        ];
    }

    public function test_audit_logs_carry_company_id_and_index(): void
    {
        app(AuditService::class)->log($this->employee->user, 'perf.test', 'employee', $this->employee->id);

        self::assertSame($this->employee->company_id, (int) DB::table('audit_logs')->value('company_id'));
        self::assertTrue(Schema::hasColumn('audit_logs', 'company_id'));
        self::assertTrue(Schema::hasIndex('audit_logs', 'audit_logs_company_id_created_at_index'));
        self::assertFalse(Schema::hasIndex('settings', 'settings_group_key_company_id_unique'));
        self::assertTrue(Schema::hasIndex('settings', 'settings_scope_unique'));
    }

    public function test_mark_read_cannot_point_to_message_from_other_conversation(): void
    {
        $chat = app(ChatService::class);
        $otherId = (int) Employee::query()->where('employee_no', 'P-2')->value('id');
        $first = $chat->createGroup($this->employee, 'Первый диалог', [$otherId]);
        $second = $chat->createGroup($this->employee, 'Второй диалог', [$otherId]);
        $foreign = $chat->send($this->employee, $second, ['body' => 'Сообщение в другом диалоге']);

        $this->expectException(ValidationException::class);
        $chat->markRead($this->employee, $first, $foreign->id);
    }
}
