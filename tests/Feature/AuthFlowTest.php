<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Sidebar;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_loads(): void
    {
        $this->get('/login')->assertStatus(200)->assertSee('Вход в систему');
    }

    public function test_demo_ceo_can_login(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(DemoSeeder::class);

        $response = $this->post('/login', [
            'email' => 'ceo@corpms.demo',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_shows_for_authenticated_ceo(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->post('/login', [
            'email' => 'ceo@corpms.demo',
            'password' => 'password',
        ]);

        $this->get('/dashboard')->assertOk()->assertSee('Дашборд');
    }

    public function test_sidebar_filtered_by_role(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(DemoSeeder::class);

        $ceo = \App\Models\User::where('email', 'ceo@corpms.demo')->first();
        $emp = \App\Models\User::where('email', 'it-emp1@corpms.demo')->first();

        $ceoItems = Sidebar::build($ceo);
        $empItems = Sidebar::build($emp);

        $ceoRoutes = array_column($ceoItems, 'route');
        $empRoutes = array_column($empItems, 'route');

        // CEO видит настройки и аудит, рядовой сотрудник — нет
        $this->assertContains('settings.index', $ceoRoutes);
        $this->assertContains('audit.index', $ceoRoutes);
        $this->assertNotContains('settings.index', $empRoutes);
        $this->assertNotContains('audit.index', $empRoutes);

        // Сотрудник видит свои задачи и документы
        $this->assertContains('tasks.index', $empRoutes);
        $this->assertContains('documents.index', $empRoutes);
        $this->assertNotContains('attendance.live', $empRoutes);
    }
}
