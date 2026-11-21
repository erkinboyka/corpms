<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Фаза A, задача 4: заголовки безопасности, параметры сессии/куки,
 * запрет APP_DEBUG в production (ТЗ §11).
 */
class SecurityHeadersHttpTest extends TestCase
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(self), microphone=(self), display-capture=(self), geolocation=()',
    ];

    public function test_security_headers_present_on_responses(): void
    {
        $response = $this->get('/login');

        $response->assertOk();

        foreach (self::SECURITY_HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    public function test_hsts_set_only_over_https(): void
    {
        // http (локальная разработка) — HSTS не должен появляться
        $this->get('/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        // https — HSTS с разумным max-age
        $this->get('https://corpms.local/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }

    public function test_session_cookie_is_hardened(): void
    {
        $response = $this->get('/login');
        $response->assertOk();

        $cookieHeader = strtolower(implode("\n", $response->headers->all('set-cookie') ?? []));
        $cookieName = (string) config('session.cookie');

        $this->assertNotSame('', $cookieName);
        $this->assertStringContainsString(strtolower($cookieName), $cookieHeader, 'в ответе должна быть кука сессии');
        $this->assertStringContainsString('httponly', $cookieHeader, 'кука сессии должна быть HttpOnly');
        $this->assertStringContainsString('samesite=lax', $cookieHeader, 'кука сессии должна быть SameSite=lax');
    }

    public function test_session_payload_encryption_enabled(): void
    {
        $this->assertTrue(
            (bool) config('session.encrypt'),
            'SESSION_ENCRYPT=true — содержимое сессии шифруется на диске (ТЗ §11 «защита сессий»)'
        );
    }

    public function test_debug_cannot_be_enabled_in_production(): void
    {
        $envBackup = [];
        $keys = ['APP_ENV', 'APP_DEBUG'];

        foreach ($keys as $key) {
            $envBackup[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }

        try {
            foreach ($keys as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
            $_ENV['APP_ENV'] = 'production';
            $_ENV['APP_DEBUG'] = 'true';
            $_SERVER['APP_ENV'] = 'production';
            $_SERVER['APP_DEBUG'] = 'true';

            $config = require base_path('config/app.php');

            $this->assertFalse(
                (bool) $config['debug'],
                'APP_DEBUG=true при APP_ENV=production должен игнорироваться'
            );
        } finally {
            foreach ($keys as $key) {
                [$env, $server] = $envBackup[$key];
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }

    public function test_debug_still_works_in_local_environment(): void
    {
        $envBackup = [];
        $keys = ['APP_ENV', 'APP_DEBUG'];

        foreach ($keys as $key) {
            $envBackup[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }

        try {
            foreach ($keys as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
            $_ENV['APP_ENV'] = 'local';
            $_ENV['APP_DEBUG'] = 'true';
            $_SERVER['APP_ENV'] = 'local';
            $_SERVER['APP_DEBUG'] = 'true';

            $config = require base_path('config/app.php');

            $this->assertTrue((bool) $config['debug'], 'в local debug должен работать как раньше');
        } finally {
            foreach ($keys as $key) {
                [$env, $server] = $envBackup[$key];
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }
}
