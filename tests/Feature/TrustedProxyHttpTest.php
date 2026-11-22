<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** TEST-04: HTTPS/cookie/client IP за доверенным reverse proxy. */
class TrustedProxyHttpTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConfig = [
            'trustedproxy.proxies' => config('trustedproxy.proxies'),
            'session.secure' => config('session.secure'),
        ];
    }

    protected function tearDown(): void
    {
        config($this->originalConfig);

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function proxyHeaders(): array
    {
        return [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ];
    }

    public function test_trusted_proxy_enables_hsts_and_secure_cookie(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1'], 'session.secure' => true]);

        $response = $this->withServerVariables($this->proxyHeaders())->get('/login');

        $response->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');

        $cookie = strtolower(implode("\n", $response->headers->all('set-cookie') ?? []));
        $this->assertStringContainsString('secure', $cookie);
    }

    public function test_untrusted_proxy_cannot_spoof_https(): void
    {
        config(['trustedproxy.proxies' => null, 'session.secure' => false]);

        $this->withServerVariables($this->proxyHeaders())
            ->get('/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_forwarded_host_and_prefix_are_not_trusted(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        Route::get('/_trust_probe', static fn (Request $request) => response()->json([
            'host' => $request->host(),
            'url' => $request->url(),
            'ip' => $request->ip(),
        ]));

        $data = $this->withServerVariables($this->proxyHeaders() + [
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PREFIX' => '/prefix',
        ])->getJson('/_trust_probe')->assertOk()->json();

        $this->assertStringNotContainsString('evil.example', (string) $data['url']);
        $this->assertStringNotContainsString('/prefix', (string) $data['url']);
        $this->assertSame('203.0.113.10', $data['ip']);
    }

    public function test_failed_login_uses_forwarded_client_ip_for_audit(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $this->withServerVariables($this->proxyHeaders())
            ->from('/login')
            ->post('/login', ['email' => 'missing@corpms.test', 'password' => 'wrong'])
            ->assertRedirect('/login');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_failed',
            'ip' => '203.0.113.10',
        ]);
    }
}
