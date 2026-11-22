<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\SearchQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** P1 BE-07/PERF-08: единый лимит и буквальный поиск wildcard-символов. */
class SearchQueryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Search Co', 'code' => 'searchco']);
        $this->user = User::factory()->create();
        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'employee_no' => 'SRCH-1',
            'first_name' => 'Админ',
            'last_name' => 'Поиска',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->user);
    }

    public function test_helper_rejects_query_longer_than_120_characters(): void
    {
        $this->expectException(ValidationException::class);

        SearchQuery::fromString(str_repeat('%', 121));
    }

    public function test_too_long_query_returns_422_json_for_search_routes(): void
    {
        $long = str_repeat('%', 10_000);

        $this->withHeaders(['Accept' => '*/*'])
            ->get('/search?q='.urlencode($long))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->getJson('/search/hint?q='.urlencode($long))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->getJson('/chat/employees?q='.urlencode($long))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_search_wildcards_are_treated_literally(): void
    {
        $literal = Task::create([
            'company_id' => $this->company->id,
            'title' => 'Отчёт 100% готов',
            'number' => 'SRCH-LITERAL',
            'type' => 'task',
            'status' => 'new',
            'author_id' => $this->employee->id,
        ]);
        $wildcard = Task::create([
            'company_id' => $this->company->id,
            'title' => 'Отчёт 100X готов',
            'number' => 'SRCH-WILDCARD',
            'type' => 'task',
            'status' => 'new',
            'author_id' => $this->employee->id,
        ]);

        $this->get('/search?q='.urlencode('100%'))
            ->assertOk()
            ->assertViewHas('tasks', fn ($tasks) => $tasks->contains('id', $literal->id))
            ->assertViewHas('tasks', fn ($tasks) => ! $tasks->contains('id', $wildcard->id));
    }

    public function test_chat_employee_search_escapes_wildcards(): void
    {
        $literal = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'SRCH-2',
            'first_name' => 'Иван%',
            'last_name' => 'Тестовый',
            'status' => 'active',
        ]);
        $other = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => 'SRCH-3',
            'first_name' => 'Иван1',
            'last_name' => 'Тестовый',
            'status' => 'active',
        ]);

        $ids = collect($this->getJson('/chat/employees?q='.urlencode('Иван%'))
            ->assertOk()
            ->json('employees'))
            ->pluck('id');

        $this->assertTrue($ids->contains($literal->id));
        $this->assertFalse($ids->contains($other->id));
    }
}
