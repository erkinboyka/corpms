<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** TEST-01/TEST-09: preflight окружения без утечки секретов в выводе. */
class CorpmsPreflightTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $keys = [
            'app.env', 'app.debug', 'app.key', 'app.url',
            'session.secure', 'session.http_only', 'session.same_site', 'session.encrypt',
            'trustedproxy.proxies', 'database.default', 'cache.default',
            'queue.default', 'filesystems.default', 'broadcasting.default',
            'services.google.client_id', 'services.google.client_secret', 'services.google.redirect',
            'corpms.telegram.bot_token', 'corpms.telegram.webhook_secret',
            'corpms.ai.base_url', 'corpms.ai.api_key',
            'boost.enabled', 'boost.browser_logs_watcher',
        ];
        foreach ($keys as $key) {
            $this->originalConfig[$key] = config($key);
        }
    }

    protected function tearDown(): void
    {
        config($this->originalConfig);

        parent::tearDown();
    }

    /** @return array{ok: bool, checks: array<int, array{name: string, ok: bool, details: string}>} */
    private function runPreflight(array $options = ['--json' => true, '--skip-config-cache' => true]): array
    {
        Artisan::call('corpms:preflight', $options);
        $report = json_decode(Artisan::output(), true);
        self::assertIsArray($report, 'Команда должна вернуть JSON: '.Artisan::output());

        return $report;
    }

    /** @param array<string, mixed> $overrides */
    private function configureProduction(array $overrides = []): void
    {
        config(array_merge([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://corpms.example',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.encrypt' => true,
            'trustedproxy.proxies' => ['127.0.0.1'],
            'database.default' => 'sqlite',
            'cache.default' => 'database',
            'queue.default' => 'database',
            'filesystems.default' => 'local',
            'broadcasting.default' => 'log',
            'services.google.client_id' => null,
            'corpms.telegram.bot_token' => '',
            'corpms.ai.base_url' => '',
        ], $overrides));
    }

    public function test_preflight_passes_on_hardened_production_config(): void
    {
        $this->configureProduction();

        $exit = Artisan::call('corpms:preflight', ['--json' => true, '--skip-config-cache' => true]);
        $report = json_decode(Artisan::output(), true);

        self::assertSame(0, $exit);
        self::assertTrue($report['ok'], Artisan::output());
    }

    public function test_empty_app_key_fails_preflight(): void
    {
        $this->configureProduction(['app.key' => '']);

        $exit = Artisan::call('corpms:preflight', ['--json' => true, '--skip-config-cache' => true]);
        $report = json_decode(Artisan::output(), true);

        self::assertSame(1, $exit);
        $appKey = collect($report['checks'])->firstWhere('name', 'app_key');
        self::assertFalse($appKey['ok']);
    }

    public function test_http_app_url_fails_preflight(): void
    {
        $this->configureProduction(['app.url' => 'http://corpms.example']);

        $report = $this->runPreflight();
        $url = collect($report['checks'])->firstWhere('name', 'app_url');

        self::assertFalse($report['ok']);
        self::assertFalse($url['ok']);
    }

    public function test_insecure_session_cookie_fails_preflight(): void
    {
        $this->configureProduction(['session.secure' => false]);

        $report = $this->runPreflight();
        $session = collect($report['checks'])->firstWhere('name', 'session');

        self::assertFalse($report['ok']);
        self::assertFalse($session['ok']);
    }

    public function test_wildcard_trusted_proxy_fails_preflight(): void
    {
        $this->configureProduction(['trustedproxy.proxies' => '*']);

        $report = $this->runPreflight();
        $proxy = collect($report['checks'])->firstWhere('name', 'trusted_proxy');

        self::assertFalse($report['ok']);
        self::assertFalse($proxy['ok']);
    }

    public function test_boost_browser_logs_in_production_fails_preflight(): void
    {
        $this->configureProduction([
            'boost.enabled' => true,
            'boost.browser_logs_watcher' => true,
        ]);

        $report = $this->runPreflight();
        $devTools = collect($report['checks'])->firstWhere('name', 'dev_tools');

        self::assertFalse($report['ok']);
        self::assertFalse($devTools['ok']);
    }

    public function test_preflight_output_does_not_leak_secret_values(): void
    {
        $secret = 'sk-leak-probe-'.bin2hex(random_bytes(8));
        $this->configureProduction([
            'corpms.ai.base_url' => 'https://ai.example/v1',
            'corpms.ai.api_key' => $secret,
        ]);

        $exit = Artisan::call('corpms:preflight', ['--json' => true, '--skip-config-cache' => true]);
        $output = Artisan::output();

        self::assertSame(0, $exit, $output);
        self::assertStringNotContainsString($secret, $output);
    }
}
