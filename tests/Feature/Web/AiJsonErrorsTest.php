<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P1 BE-03: ошибки валидации JSON-эндпоинта остаются JSON без Accept: application/json. */
class AiJsonErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $company = Company::create(['name' => 'AI JSON Co', 'code' => 'aijson']);
        $user = User::factory()->create();
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'AI-1',
            'first_name' => 'Эй',
            'last_name' => 'Ай',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($user);
    }

    public function test_ai_message_validation_returns_json_with_wildcard_accept(): void
    {
        $response = $this->withHeaders(['Accept' => '*/*'])
            ->post('/ai/message', ['question' => 'ab']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('question');
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }
}
