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

/**
 * C3: палитра команд Ctrl+K — разрешённые разделы, быстрый поиск, доступ с 360px.
 */
class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Palette Co', 'code' => 'paletteco']);
        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Админов', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Ольга', 'Рядовая', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    /** Палитра присутствует, открытие доступно кнопкой (в т.ч. на 360px). */
    public function test_palette_renders_with_open_button_on_page(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->get('/dashboard')->assertOk();

        $response->assertSee('id="crm-palette"', false);
        $response->assertSee('data-crm-palette-open', false);
        $response->assertSee('id="crm-palette-input"', false);
        // хоткей зафиксирован в разметке (подсказка в футере палитры)
        $response->assertSee('Ctrl K', false);
    }

    /** Команды палитры = разделы, доступные пользователю (permission-фильтр как в сайдбаре). */
    public function test_palette_commands_filtered_by_permission(): void
    {
        $this->actingAs($this->adminUser);
        $adminHtml = (string) $this->get('/dashboard')->assertOk()->getContent();

        $adminCommands = $this->commandsFrom($adminHtml);
        $titles = array_column($adminCommands, 'title');

        self::assertContains('Дашборд', $titles, 'super_admin видит Дашборд');
        self::assertContains('Настройки', $titles, 'super_admin видит Настройки');
        self::assertContains('Аналитика', $titles);
        // каждый элемент имеет URL и секцию
        foreach ($adminCommands as $command) {
            self::assertNotEmpty($command['url']);
            self::assertArrayHasKey('section', $command);
        }

        // рядовой сотрудник: без settings.view/audit.view — этих команд нет
        $this->actingAs($this->workerUser);
        $workerHtml = (string) $this->get('/dashboard')->assertOk()->getContent();
        $workerTitles = array_column($this->commandsFrom($workerHtml), 'title');

        self::assertContains('Мои задачи', $workerTitles, 'сотрудник видит свои задачи');
        self::assertNotContains('Настройки', $workerTitles, 'сотрудник не видит Настройки');
        self::assertNotContains('Audit Log', $workerTitles, 'сотрудник не видит Audit Log');
        self::assertNotContains('Компании', $workerTitles, 'сотрудник не видит Компании');
    }

    /** Быстрый поиск в палитре доступен только при праве search.global. */
    public function test_palette_search_entry_requires_permission(): void
    {
        $this->actingAs($this->adminUser);
        $adminHtml = (string) $this->get('/dashboard')->assertOk()->getContent();
        self::assertStringContainsString('data-search-url="'.htmlspecialchars(route('search.index'), ENT_QUOTES).'"', $adminHtml);

        $this->actingAs($this->workerUser);
        $workerHtml = (string) $this->get('/dashboard')->assertOk()->getContent();
        // у сотрудника права search.global нет — URL поиска пустой
        self::assertMatchesRegularExpression('/data-search-url=""/', $workerHtml);
    }

    /** Разметка палитры валидна: JSON команд парсится и не ломает атрибут. */
    public function test_palette_commands_json_is_valid(): void
    {
        $this->actingAs($this->adminUser);
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        $commands = $this->commandsFrom($html);
        self::assertNotEmpty($commands);
        self::assertContains('Дашборд', array_column($commands, 'title'));
    }

    /**
     * @return array<int, array{title: string, section: string, icon: string, url: string}>
     */
    protected function commandsFrom(string $html): array
    {
        if (! preg_match('/data-commands="([^"]*)"/u', $html, $m)) {
            self::fail('data-commands не найден в HTML');
        }

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
        self::assertIsArray($decoded, 'data-commands должен быть валидным JSON');

        return $decoded;
    }
}
