<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\BoostBrowserLogsGuard;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** P1 SEC-04: dev-роут Boost закрыт в prod, имеет allowlist и rate limit. */
class BoostBrowserLogsGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_boost/guard-probe', static fn () => response()->json(['ok' => true]))
            ->middleware(BoostBrowserLogsGuard::class);

        config([
            'boost.enabled' => true,
            'boost.browser_logs_watcher' => true,
            'boost.browser_logs_allowed_ips' => ['127.0.0.1'],
        ]);
        RateLimiter::clear('boost.browser-logs:127.0.0.1');
        RateLimiter::clear('boost.browser-logs:198.51.100.10');
    }

    public function test_route_is_hidden_outside_local_environment(): void
    {
        // phpunit.xml задаёт testing — это не local, поэтому Boost недоступен.
        $this->app->detectEnvironment(static fn () => 'testing');

        $this->getJson('/_boost/guard-probe')->assertNotFound();
    }

    public function test_only_allowlisted_ip_can_use_route(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/_boost/guard-probe')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->getJson('/_boost/guard-probe')
            ->assertForbidden();
    }

    public function test_route_is_rate_limited(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->getJson('/_boost/guard-probe')
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/_boost/guard-probe')
            ->assertStatus(429);
    }
}
