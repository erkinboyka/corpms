<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Консервативный SW: только версионированные статические файлы витрины.
 * HTML, аутентификация и данные не попадают в кэш и не регистрируются.
 */
final class ServiceWorkerTest extends TestCase
{
    public function test_script_is_public_and_has_no_cache_headers(): void
    {
        config()->set('corpms.service_worker.enabled', true);

        $response = $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));

        $script = $response->getContent();

        self::assertStringContainsString('const ENABLED = true;', $script);
        self::assertStringContainsString("request.method !== 'GET'", $script);
        self::assertStringContainsString('url.origin !== self.location.origin', $script);
        self::assertStringContainsString('VERSIONED_QUERY', $script);
        self::assertStringContainsString('FINGERPRINTED', $script);
        self::assertStringContainsString('registration.unregister()', $script);

        // Никаких веток для HTML/навигации/данных.
        self::assertStringNotContainsString('navigate', $script);
        self::assertStringNotContainsString('/login', $script);
        self::assertStringNotContainsString('/api/', $script);
        self::assertStringNotContainsString('/dashboard', $script);
    }

    public function test_disabled_flag_unregisters_existing_worker(): void
    {
        config()->set('corpms.service_worker.enabled', false);

        $script = $this->get('/sw.js')->assertOk()->getContent();

        self::assertStringContainsString('const ENABLED = false;', $script);
        self::assertStringContainsString('registration.unregister()', $script);
    }

    public function test_registration_is_only_on_public_landing(): void
    {
        config()->set('corpms.service_worker.enabled', true);

        $landing = $this->get('/')->assertOk()->getContent();
        self::assertStringContainsString("register('/sw.js'", $landing);

        $login = $this->get('/login')->assertOk()->getContent();
        self::assertStringNotContainsString("register('/sw.js'", $login);

        config()->set('corpms.service_worker.enabled', false);
        $landingOff = $this->get('/')->assertOk()->getContent();
        self::assertStringNotContainsString("register('/sw.js'", $landingOff);
    }
}
