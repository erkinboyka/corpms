<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P1 SEC-10/BE-02: гость получает 403 на всех шаблонах каналов, включая непрефиксированные. */
class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options' => [
                'host' => '127.0.0.1',
                'port' => 8080,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ]);

        // routes/channels.php уже выполнился на null-драйвере; повторно
        // регистрируем колбэки на новом reverb-драйвере.
        app(Factory::class)->forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_guest_is_forbidden_for_every_channel_pattern(): void
    {
        foreach ([
            'chat.conversation.1',
            'employee.1.chat',
            'App.Models.User.1',
            'private-chat.conversation.1',
        ] as $channel) {
            $response = $this->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id' => '1.1',
            ]);

            $response->assertForbidden();

            $body = $response->getContent();
            $this->assertStringNotContainsString('Stack trace', $body);
            $this->assertStringNotContainsString('TypeError', $body);
            $this->assertStringNotContainsString('Error:', $body);
        }
    }
}
