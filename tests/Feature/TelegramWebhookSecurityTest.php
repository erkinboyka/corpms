<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** P1 SEC-06/BE-04: секрет только в header, constant-time, валидация update. */
class TelegramWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'corpms.telegram.webhook_secret' => 'webhook-secret-value',
            'corpms.telegram.bot_token' => '999:test',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_secret_in_path_is_no_longer_accepted(): void
    {
        $this->postJson(
            '/api/telegram/webhook/webhook-secret-value',
            $this->update(),
            ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret-value'],
        )->assertNotFound();
    }

    public function test_header_is_required_and_compared_in_constant_time(): void
    {
        $this->postJson('/api/telegram/webhook', $this->update())->assertForbidden();

        $this->postJson(
            '/api/telegram/webhook',
            $this->update(),
            ['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret'],
        )->assertForbidden();

        $this->postJson(
            '/api/telegram/webhook',
            $this->update(),
            ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret-value'],
        )->assertOk()->assertJsonPath('ok', true)->assertJsonPath('handled', true);
    }

    public function test_empty_configuration_fails_closed(): void
    {
        config(['corpms.telegram.webhook_secret' => '']);

        $this->postJson(
            '/api/telegram/webhook',
            $this->update(),
            ['X-Telegram-Bot-Api-Secret-Token' => ''],
        )->assertForbidden();
    }

    public function test_malformed_updates_are_rejected(): void
    {
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret-value'];

        $this->postJson('/api/telegram/webhook', [], $headers)->assertStatus(422);
        $this->call(
            'POST',
            '/api/telegram/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'webhook-secret-value',
            ],
            'null',
        )->assertStatus(422);
        $this->postJson('/api/telegram/webhook', ['update_id' => 'bad'], $headers)->assertStatus(422);
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => ['text' => '/help'],
        ], $headers)->assertStatus(422);
    }

    /** @return array<string, mixed> */
    private function update(): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'text' => '/help',
                'chat' => ['id' => 1],
            ],
        ];
    }
}
