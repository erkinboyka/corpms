<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

/**
 * Фаза A, задача 7: обработка ошибок и страницы ошибок (ТЗ §11).
 *
 * - кастомные страницы в стиле проекта (не дефолтные страницы Laravel);
 * - сообщения исключений не утекают в 404/500/503 (пути, SQL, данные);
 * - 403 показывает только наше безопасное сообщение про права;
 * - API-запросы получают JSON-ошибки, а не HTML.
 */
class ErrorPagesHttpTest extends TestCase
{
    use RefreshDatabase;

    /* -------------------- HTML-страницы -------------------- */

    public function test_404_renders_custom_page_without_leaking_details(): void
    {
        $response = $this->get('/net-takoy-stranitsy-'.uniqid());

        $response->assertNotFound();
        $response->assertSee('Страница не найдена');
        $response->assertSee('BM Systems');

        // сообщение исключения и внутренние детали не показываются
        $response->assertDontSee('SQLSTATE');
        $response->assertDontSee('storage', false);
    }

    public function test_403_shows_safe_permission_message_only(): void
    {
        $this->seed(RbacSeeder::class);

        // пользователь без роли и карточки не имеет audit.view
        $this->actingAs(User::factory()->create());

        $response = $this->get('/audit');

        $response->assertForbidden();
        $response->assertSee('Доступ запрещён');
        // наше сообщение из EnsurePermission — безопасно и полезно пользователю
        $response->assertSee('Недостаточно прав: audit.view');
        // но пути/SQL не должны утекать
        $response->assertDontSee('SQLSTATE');
    }

    public function test_api_missing_route_returns_json_404_not_html(): void
    {
        $response = $this->getJson('/api/net-takogo-endpoint-'.uniqid());

        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertArrayHasKey('message', $response->json());
        $this->assertStringNotContainsString('<html', $response->getContent() ?: '');
    }

    /* -------------------- Прямой рендер views ошибок -------------------- */

    public function test_401_page_offers_login(): void
    {
        $html = view('errors.401', [
            'exception' => null,
            'errors' => new ViewErrorBag,
        ])->render();

        self::assertStringContainsString('Требуется вход', $html);
        self::assertStringContainsString('Войти', $html);
    }

    public function test_500_page_hides_exception_message_completely(): void
    {
        $html = view('errors.500', [
            'exception' => new RuntimeException('SQLSTATE[HY000] secret/db/path.sqlite'),
            'errors' => new ViewErrorBag,
        ])->render();

        self::assertStringContainsString('Внутренняя ошибка сервера', $html);
        self::assertStringNotContainsString('SQLSTATE', $html);
        self::assertStringNotContainsString('secret/db/path', $html);
    }

    public function test_503_and_419_pages_render_with_project_branding(): void
    {
        foreach ([
            503 => 'Технические работы',
            419 => 'Сессия устарела',
            429 => 'Слишком много запросов',
        ] as $code => $title) {
            $html = view("errors.{$code}", [
                'exception' => null,
                'errors' => new ViewErrorBag,
            ])->render();

            self::assertStringContainsString($title, $html, "страница {$code}");
            self::assertStringContainsString('BM Systems', $html, "страница {$code}");
        }
    }
}
