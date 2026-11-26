<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domains\Ai\Services\BumiPersonaService;
use App\Models\BumiAgent;
use App\Shared\Enums\BumiMood;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Настроение Bumi обязано иметь причину: flash, ошибку, маршрут или время.
 * Проверяем сервис напрямую, без случайных таймеров на клиенте.
 */
class BumiPersonaTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_working_hours_are_happy(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 26, 12, 0, 0));
        $this->bindRequest();

        $persona = app(BumiPersonaService::class)->current();

        self::assertSame(BumiMood::Happy, $persona->mood);
        self::assertSame('ready', $persona->reason);
        self::assertNotSame('', $persona->line);
    }

    public function test_late_hours_make_bumi_sleepy(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 26, 23, 30, 0));
        $this->bindRequest();

        $persona = app(BumiPersonaService::class)->current();

        self::assertSame(BumiMood::Sleepy, $persona->mood);
        self::assertSame('after_hours', $persona->reason);
    }

    public function test_success_flash_makes_bumi_proud(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 26, 23, 30, 0));
        $this->bindRequest(['status' => 'Сохранено']);

        $persona = app(BumiPersonaService::class)->current();

        // Свежее событие важнее времени суток.
        self::assertSame(BumiMood::Proud, $persona->mood);
        self::assertSame('success', $persona->reason);
    }

    public function test_validation_errors_make_bumi_sad(): void
    {
        $this->bindRequest(['errors' => new MessageBag(['title' => ['Обязательное поле']])]);

        $persona = app(BumiPersonaService::class)->current();

        self::assertSame(BumiMood::Sad, $persona->mood);
        self::assertSame('validation', $persona->reason);
    }

    public function test_permission_error_is_the_only_reason_to_be_angry(): void
    {
        $this->bindRequest(['error' => 'У вас нет доступа к этому разделу']);

        $persona = app(BumiPersonaService::class)->current();

        self::assertSame(BumiMood::Angry, $persona->mood);
        self::assertSame('denied', $persona->reason);
    }

    public function test_chat_route_is_thinking(): void
    {
        $this->bindRequest([], 'ai.index');

        $persona = app(BumiPersonaService::class)->current();

        self::assertSame(BumiMood::Thinking, $persona->mood);
        self::assertSame('chat', $persona->reason);
    }

    public function test_paused_agent_is_sleepy(): void
    {
        $agent = new BumiAgent(['enabled' => false]);
        $this->bindRequest([], 'bumi.settings.agent', ['agent' => $agent]);

        $persona = app(BumiPersonaService::class)->current();

        self::assertSame(BumiMood::Sleepy, $persona->mood);
        self::assertSame('agent_paused', $persona->reason);
    }

    public function test_face_partial_exposes_mood_line_and_reason_through_attributes(): void
    {
        $html = View::make('partials.bumi-face', [
            'class' => 'bm-assistant__badge',
            'mood' => 'angry',
            'line' => 'Доступ закрыт',
            'reason' => 'denied',
        ])->render();

        self::assertStringContainsString('data-bumi-face', $html);
        self::assertStringContainsString('data-bumi-mood="angry"', $html);
        self::assertStringContainsString('data-bumi-reason="denied"', $html);
        self::assertStringNotContainsString('data-bumi-line', $html);
        self::assertStringContainsString('bumi-face__expression--angry', $html);
    }

    /** @param array<string, mixed> $sessionData @param array<string, mixed> $parameters */
    private function bindRequest(array $sessionData = [], ?string $routeName = null, array $parameters = []): void
    {
        $request = Request::create('/test', 'GET');
        $session = $this->app->make('session.store');
        foreach ($sessionData as $key => $value) {
            $session->flash($key, $value);
        }
        $request->setLaravelSession($session);

        if ($routeName !== null) {
            $route = new Route('GET', '/test/{agent?}', static fn () => null);
            $route->name($routeName);
            $route->bind($request);
            foreach ($parameters as $key => $value) {
                $route->setParameter($key, $value);
            }
            $request->setRouteResolver(static fn () => $route);
        }

        $this->app->instance('request', $request);
    }
}
