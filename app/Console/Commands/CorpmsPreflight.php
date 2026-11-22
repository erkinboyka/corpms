<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * TEST-09: проверка production-окружения перед релизом.
 *
 * Читает только config(), поэтому проверки можно прогонять в CI/тестах без
 * реальных секретов. Никакие значения секретов не попадают в вывод.
 */
class CorpmsPreflight extends Command
{
    protected $signature = 'corpms:preflight
        {--json : Вывести отчёт в JSON}
        {--skip-config-cache : Не требовать bootstrap/cache/config.php}';

    protected $description = 'Проверить preflight production-окружения: ключ, HTTPS, сессию, proxy, интеграции';

    public function handle(): int
    {
        $checks = [
            $this->check('environment', in_array(config('app.env'), ['production', 'staging'], true),
                'окружение: '.(string) config('app.env')),
            $this->check('debug', config('app.debug') === false, 'APP_DEBUG выключен'),
            $this->check('app_key', $this->keyBytes(config('app.key')) === 32, 'ключ приложения: 32 байта'),
            $this->check('app_url', $this->isHttpsUrl(config('app.url')), 'APP_URL использует HTTPS'),
            $this->check('session', $this->sessionHardened(), 'сессия: Secure + HttpOnly + SameSite + encrypt'),
            $this->check('trusted_proxy', $this->trustedProxiesConfigured(), 'доверенные proxy заданы и не "*"'),
            $this->check('database', $this->hasConfig('database.default'), 'database.default задан'),
            $this->check('cache', $this->hasConfig('cache.default'), 'cache.default задан'),
            $this->check('queue', $this->hasConfig('queue.default'), 'queue.default задан'),
            $this->check('filesystem', $this->hasConfig('filesystems.default'), 'filesystems.default задан'),
            $this->check('config_cache', $this->configCacheReady(), 'bootstrap/cache/config.php актуален'),
            $this->check('dev_tools', $this->devToolsDisabled(), 'dev-инструменты (Boost) в prod выключены'),
            $this->check('integrations', $this->integrationsConfigured(), 'включённые интеграции заполнены'),
        ];

        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $ok,
                'checked_at' => now()->toIso8601String(),
                'checks' => $checks,
            ], JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Проверка', 'Статус', 'Детали'],
                array_map(static fn (array $check): array => [
                    $check['name'],
                    $check['ok'] ? '<info>ok</info>' : '<error>fail</error>',
                    $check['details'],
                ], $checks),
            );
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{name: string, ok: bool, details: string} */
    private function check(string $name, bool $ok, string $details): array
    {
        return ['name' => $name, 'ok' => $ok, 'details' => $details];
    }

    private function keyBytes(mixed $key): int
    {
        if (! is_string($key) || $key === '') {
            return 0;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? 0 : strlen($decoded);
        }

        return strlen($key);
    }

    private function isHttpsUrl(mixed $url): bool
    {
        return is_string($url)
            && str_starts_with(strtolower(parse_url($url, PHP_URL_SCHEME) ?: ''), 'https');
    }

    private function sessionHardened(): bool
    {
        return (bool) config('session.secure')
            && (bool) config('session.http_only')
            && (bool) config('session.encrypt')
            && config('session.same_site') !== null
            && config('session.same_site') !== '';
    }

    private function trustedProxiesConfigured(): bool
    {
        $proxies = config('trustedproxy.proxies');

        if (is_string($proxies)) {
            $proxies = array_map(trim(...), explode(',', $proxies));
        }

        if (! is_array($proxies) || $proxies === []) {
            return false;
        }

        foreach ($proxies as $proxy) {
            if (! is_string($proxy) || in_array($proxy, ['*', '**'], true)) {
                return false;
            }
        }

        return true;
    }

    private function hasConfig(string $key): bool
    {
        $value = config($key);

        return is_string($value) && $value !== '';
    }

    private function configCacheReady(): bool
    {
        if ($this->option('skip-config-cache')) {
            return true;
        }

        return config('app.env') !== 'production' || is_file(base_path('bootstrap/cache/config.php'));
    }

    private function devToolsDisabled(): bool
    {
        if (config('app.env') !== 'production') {
            return true;
        }

        return ! (bool) config('boost.enabled') || ! (bool) config('boost.browser_logs_watcher');
    }

    private function integrationsConfigured(): bool
    {
        if (config('broadcasting.default') === 'reverb') {
            foreach (['app_id', 'key', 'secret'] as $key) {
                if (! $this->hasConfig('broadcasting.connections.reverb.'.$key)) {
                    return false;
                }
            }
        }

        if ($this->hasConfig('services.google.client_id')
            && (! $this->hasConfig('services.google.client_secret') || ! $this->hasConfig('services.google.redirect'))) {
            return false;
        }

        if ($this->hasConfig('corpms.telegram.bot_token') && ! $this->hasConfig('corpms.telegram.webhook_secret')) {
            return false;
        }

        if ($this->hasConfig('corpms.ai.base_url') && ! $this->hasConfig('corpms.ai.api_key')) {
            return false;
        }

        return true;
    }
}
