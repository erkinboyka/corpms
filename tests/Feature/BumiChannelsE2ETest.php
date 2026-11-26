<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Ai\Jobs\ProcessBumiChannelMessage;
use App\Domains\Ai\Services\BumiAgentService;
use App\Domains\Ai\Services\BumiKnowledgeService;
use App\Domains\DashboardGen\Services\AiClient;
use App\Models\AuditLog;
use App\Models\BumiAgent;
use App\Models\BumiChannel;
use App\Models\BumiChannelContact;
use App\Models\BumiChannelMessage;
use App\Models\Company;
use App\Models\CrmLead;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\BumiChannelType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bumi: реальный контур webhook → автоответ → доставка → аудит.
 *
 * Проверяет не только рендер настроек, а provider-контракты, идемпотентность,
 * изоляцию тенантов и то, что ошибка доставки не маскируется как «отвечено».
 */
final class BumiChannelsE2ETest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private BumiAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Bumi E2E', 'code' => 'bumie2e']);
        $this->user = $this->makeUser('bumi-e2e@corpms.demo', $this->company);
        $this->agent = app(BumiAgentService::class)->ensureDefaults($this->company->id, $this->user->id);
    }

    public function test_public_chat_does_not_load_lms_or_realtime_assets(): void
    {
        $channel = $this->channel(BumiChannelType::Website);

        $this->get(route('bumi.chat.show', $channel))
            ->assertOk()
            ->assertSee('Bumi', false)
            ->assertDontSee('/assets/js/lms.js', false)
            ->assertDontSee('/assets/css/lms.css', false)
            ->assertDontSee('/assets/css/legal.css', false)
            ->assertDontSee('/assets/vendor/echo/pusher.min.js', false)
            ->assertDontSee('window.LMS_I18N', false);
    }

    public function test_whatsapp_webhook_is_verified_idempotent_and_delivers_through_graph(): void
    {
        $channel = $this->channel(BumiChannelType::WhatsApp);
        $channel->forceFill([
            'enabled' => true,
            'credentials' => [
                'app_secret' => 'app-secret-1234567890',
                'access_token' => 'EAABtoken123',
                'phone_number_id' => '1234567890',
                'verify_token' => 'verify-me-123',
            ],
        ])->save();

        $verifyUrl = route('bumi.channel.webhook.whatsapp.verify', $channel);
        $this->get($verifyUrl.'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc')->assertForbidden();
        $this->get($verifyUrl.'?hub_mode=subscribe&hub_verify_token=verify-me-123&hub_challenge=abc')
            ->assertOk()
            ->assertJsonPath('challenge', 'abc');

        $raw = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => '79990000000', 'profile' => ['name' => 'Иван']]],
                        'messages' => [[
                            'id' => 'wamid.1',
                            'from' => '79990000000',
                            'type' => 'text',
                            'text' => ['body' => 'Здравствуйте, есть доставка?'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $url = route('bumi.channel.webhook.whatsapp', $channel);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertForbidden();

        Queue::fake();
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret-1234567890'),
        ];

        $this->call('POST', $url, [], [], [], $headers, $raw)
            ->assertOk()
            ->assertJsonPath('handled', 1);

        $incoming = BumiChannelMessage::query()->where('external_message_id', 'wa:wamid.1')->firstOrFail();
        $this->assertSame('queued', $incoming->status);
        $this->assertDatabaseHas('bumi_channel_contacts', [
            'channel_id' => $channel->id,
            'external_id' => '79990000000',
            'display_name' => 'Иван',
        ]);
        $this->assertDatabaseHas('crm_leads', [
            'company_id' => $this->company->id,
            'source' => 'whatsapp',
        ]);
        Queue::assertPushed(ProcessBumiChannelMessage::class);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'crm.lead_created',
            'object_type' => 'crm_lead',
            'company_id' => $this->company->id,
        ]);

        // Повтор провайдера: тот же message_id, без второго лида и второй очереди.
        $this->call('POST', $url, [], [], [], $headers, $raw)->assertOk();
        $this->assertSame(1, BumiChannelMessage::query()->where('channel_id', $channel->id)->where('direction', 'in')->count());
        $this->assertSame(1, BumiChannelContact::query()->where('channel_id', $channel->id)->count());
        $this->assertSame(1, CrmLead::query()->where('company_id', $this->company->id)->count());

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        app()->call([new ProcessBumiChannelMessage($incoming->id), 'handle']);

        $outgoing = BumiChannelMessage::query()->where('reply_to_id', $incoming->id)->firstOrFail();
        $this->assertSame('out', $outgoing->direction->value);
        $this->assertSame('sent', $outgoing->status);
        $this->assertSame('answered', $incoming->fresh()->status);
        $this->assertStringContainsString('Bumi', (string) $outgoing->text);

        Http::assertSentCount(1);
        Http::assertSent(static function ($request) use ($outgoing): bool {
            return str_contains($request->url(), 'graph.facebook.com/v20.0/1234567890/messages')
                && ($request->data()['messaging_product'] ?? null) === 'whatsapp'
                && ($request->data()['to'] ?? null) === '79990000000'
                && ($request->data()['text']['body'] ?? null) === $outgoing->text;
        });

        $audit = AuditLog::query()->where('action', 'ai.bumi_auto_replied')->firstOrFail();
        $this->assertSame($this->company->id, (int) $audit->company_id);
        $this->assertTrue($audit->new_value['delivered']);
    }

    public function test_greenapi_webhook_checks_instance_and_sends_to_fixed_endpoint(): void
    {
        $channel = $this->channel(BumiChannelType::GreenApi);
        $channel->forceFill([
            'enabled' => true,
            'credentials' => [
                'instance_id' => '1101000001',
                'api_token' => str_repeat('a', 32),
                'webhook_secret' => 'green-secret-123',
            ],
        ])->save();

        $payload = static fn (string|int $instance): array => [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => $instance],
            'idMessage' => 'BAE5F4886F1A9',
            'senderData' => ['chatId' => '79990000000@c.us', 'senderName' => 'Иван'],
            'messageData' => ['textMessageData' => ['textMessage' => 'Здравствуйте']],
        ];

        $url = route('bumi.channel.webhook.greenapi', $channel);
        $headers = ['X-Green-API-Token' => 'green-secret-123'];

        $this->postJson($url, [], ['X-Green-API-Token' => 'wrong'])->assertForbidden();
        $this->postJson($url, $payload(9999999999), $headers)->assertForbidden();
        $this->assertSame(0, BumiChannelMessage::query()->where('channel_id', $channel->id)->count());

        Queue::fake();
        $this->postJson($url, $payload(1101000001), $headers)
            ->assertOk()
            ->assertJsonPath('handled', true);

        $incoming = BumiChannelMessage::query()->where('external_message_id', 'ga:BAE5F4886F1A9')->firstOrFail();
        $this->assertDatabaseHas('crm_leads', [
            'company_id' => $this->company->id,
            'source' => 'whatsapp',
        ]);
        Queue::assertPushed(ProcessBumiChannelMessage::class);

        Http::fake(['api.green-api.com/*' => Http::response(['idMessage' => 'out-1'], 200)]);

        app()->call([new ProcessBumiChannelMessage($incoming->id), 'handle']);

        Http::assertSentCount(1);
        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/waInstance1101000001/sendMessage/'.str_repeat('a', 32))
                && ($request->data()['chatId'] ?? null) === '79990000000@c.us'
                && is_string($request->data()['message'] ?? null);
        });
        $this->assertSame('sent', BumiChannelMessage::query()->where('reply_to_id', $incoming->id)->value('status'));
    }

    public function test_failed_provider_delivery_is_not_reported_as_answered(): void
    {
        $channel = $this->channel(BumiChannelType::Telegram);
        $channel->forceFill([
            'enabled' => true,
            'credentials' => ['bot_token' => '123456:ABCdef', 'webhook_secret' => 'secret-token-123'],
        ])->save();

        Queue::fake();
        $this->postJson(route('bumi.channel.webhook.telegram', $channel), [
            'update_id' => 10,
            'message' => [
                'message_id' => 77,
                'chat' => ['id' => 555],
                'text' => 'Здравствуйте!',
                'from' => ['first_name' => 'Мария'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token-123'])->assertOk();

        $incoming = BumiChannelMessage::query()->where('external_message_id', 'tg:77')->firstOrFail();

        Http::fake(['api.telegram.org/*' => Http::response('upstream exploded', 500)]);

        app()->call([new ProcessBumiChannelMessage($incoming->id), 'handle']);

        $outgoing = BumiChannelMessage::query()->where('reply_to_id', $incoming->id)->firstOrFail();
        $this->assertSame('failed', $outgoing->status);
        $this->assertSame('delivery_failed', $incoming->fresh()->status);
        $this->assertSame('Ошибка доставки', $outgoing->statusLabel());
        $this->assertSame('Ответ не доставлен', $incoming->fresh()->statusLabel());

        $audit = AuditLog::query()->where('action', 'ai.bumi_auto_replied')->firstOrFail();
        $this->assertSame($this->company->id, (int) $audit->company_id);
        $this->assertFalse($audit->new_value['delivered']);
        $this->assertSame($outgoing->id, (int) $audit->object_id);
    }

    public function test_website_visitor_threads_are_isolated_and_replays_are_idempotent(): void
    {
        $channel = $this->channel(BumiChannelType::Website);
        Queue::fake();

        $url = route('bumi.channel.messages.store', $channel);
        $this->postJson($url, [
            'visitor' => 'visitor-aaaaaaaaaaaaaaaa',
            'message' => 'Вопрос первого клиента',
            'client_message_id' => 'a-1',
        ])->assertCreated();
        $this->postJson($url, [
            'visitor' => 'visitor-bbbbbbbbbbbbbbbb',
            'message' => 'Вопрос второго клиента',
            'client_message_id' => 'b-1',
        ])->assertCreated();

        $this->getJson(route('bumi.channel.messages', $channel).'?visitor=visitor-bbbbbbbbbbbbbbbb')
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.text', 'Вопрос второго клиента');
        $this->getJson(route('bumi.channel.messages', $channel).'?visitor=visitor-aaaaaaaaaaaaaaaa')
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.text', 'Вопрос первого клиента');
        $this->getJson(route('bumi.channel.messages', $channel).'?visitor=short')->assertUnprocessable();

        $this->postJson($url, [
            'visitor' => 'visitor-aaaaaaaaaaaaaaaa',
            'message' => 'Вопрос первого клиента',
            'client_message_id' => 'a-1',
        ])->assertCreated();

        $this->assertSame(2, CrmLead::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(2, BumiChannelMessage::query()->where('channel_id', $channel->id)->where('direction', 'in')->count());
    }

    public function test_webhook_signature_cannot_be_borrowed_between_tenants(): void
    {
        $otherCompany = Company::create(['name' => 'Bumi Other', 'code' => 'bumiother']);
        $otherUser = $this->makeUser('bumi-e2e-other@corpms.demo', $otherCompany);
        app(BumiAgentService::class)->ensureDefaults($otherCompany->id, $otherUser->id);

        $a = $this->channel(BumiChannelType::WhatsApp);
        $a->forceFill([
            'enabled' => true,
            'credentials' => [
                'app_secret' => 'secret-company-a-123',
                'access_token' => 'token-a',
                'phone_number_id' => '1111111111',
                'verify_token' => 'verify-a',
            ],
        ])->save();

        $b = BumiChannel::query()
            ->where('company_id', $otherCompany->id)
            ->where('type', BumiChannelType::WhatsApp->value)
            ->firstOrFail();
        $b->forceFill([
            'enabled' => true,
            'credentials' => [
                'app_secret' => 'secret-company-b-123',
                'access_token' => 'token-b',
                'phone_number_id' => '2222222222',
                'verify_token' => 'verify-b',
            ],
        ])->save();

        $raw = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.tenant',
                            'from' => '79990000000',
                            'type' => 'text',
                            'text' => ['body' => ' tenant'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Queue::fake();
        $call = function (BumiChannel $channel, string $secret) use ($raw) {
            return $this->call('POST', route('bumi.channel.webhook.whatsapp', $channel), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
            ], $raw);
        };

        $call($b, 'secret-company-a-123')->assertForbidden();
        $call($a, 'secret-company-b-123')->assertForbidden();
        $this->assertSame(0, BumiChannelMessage::query()->whereIn('channel_id', [$a->id, $b->id])->count());

        $call($a, 'secret-company-a-123')->assertOk()->assertJsonPath('handled', 1);
        $this->assertDatabaseHas('bumi_channel_messages', [
            'company_id' => $this->company->id,
            'channel_id' => $a->id,
            'external_message_id' => 'wa:wamid.tenant',
        ]);
        $this->assertSame(0, BumiChannelMessage::query()->where('channel_id', $b->id)->count());
        Queue::assertPushed(ProcessBumiChannelMessage::class, 1);
    }

    public function test_ai_failure_uses_deterministic_fallback_and_keeps_tenant_audit(): void
    {
        $this->agent->forceFill([
            'knowledge' => '',
            'fallback_reply' => 'Мы уточним ответ и вернёмся.',
        ])->save();

        $channel = $this->channel(BumiChannelType::Website);
        Queue::fake();
        $response = $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => 'visitor-fallback-123456',
            'message' => 'Расскажите про доставку',
            'client_message_id' => 'fallback-1',
        ])->assertCreated();

        $incoming = BumiChannelMessage::query()->findOrFail($response->json('message_id'));

        // В тесте нет задачи проверять RAG: выключаем поиск по документам,
        // чтобы падение LLM однозначно приводило к fallback-строке агента.
        $this->mock(BumiKnowledgeService::class)
            ->shouldReceive('context')->andReturn([]);

        $ai = $this->mock(AiClient::class);
        $ai->shouldIgnoreMissing();
        $ai->shouldReceive('chatText')->once()->andReturn([
            'content' => null,
            'json' => null,
            'error' => 'boom',
            'tokens' => 0,
        ]);

        app()->call([new ProcessBumiChannelMessage($incoming->id), 'handle']);

        $outgoing = BumiChannelMessage::query()->where('reply_to_id', $incoming->id)->firstOrFail();
        $this->assertSame('Мы уточним ответ и вернёмся.', $outgoing->text);
        $this->assertSame('sent', $outgoing->status);
        $this->assertSame('answered', $incoming->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.bumi_auto_replied',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_rate_limited_status_is_visible_in_website_history(): void
    {
        $this->agent->forceFill(['max_replies_per_hour' => 1])->save();
        $channel = $this->channel(BumiChannelType::Website);
        $channel->forceFill(['enabled' => true])->save();

        Queue::fake();
        $created = $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => 'visitor-key-limit-123456',
            'message' => 'Вопрос после лимита',
            'client_message_id' => 'limit-1',
        ])->assertCreated();

        $incoming = BumiChannelMessage::query()->findOrFail($created->json('message_id'));
        BumiChannelMessage::query()->create([
            'company_id' => $this->company->id,
            'channel_id' => $channel->id,
            'agent_id' => $incoming->agent_id,
            'contact_id' => $incoming->contact_id,
            'external_message_id' => 'limit-seed-out',
            'direction' => 'out',
            'text' => 'Предыдущий ответ',
            'status' => 'sent',
        ]);

        app()->call([new ProcessBumiChannelMessage($incoming->id), 'handle']);

        $this->assertSame('rate_limited', $incoming->fresh()->status);
        $this->assertSame(0, BumiChannelMessage::query()->where('reply_to_id', $incoming->id)->count());

        $this->getJson(route('bumi.channel.messages', $channel).'?visitor=visitor-key-limit-123456')
            ->assertOk()
            ->assertJsonPath('messages.0.status', 'rate_limited')
            ->assertJsonPath('messages.0.status_label', 'Лимит ответов')
            ->assertJsonPath('messages.0.client_message_id', 'limit-1');
    }

    private function channel(BumiChannelType $type): BumiChannel
    {
        return BumiChannel::query()
            ->where('company_id', $this->company->id)
            ->where('type', $type->value)
            ->firstOrFail();
    }

    private function makeUser(string $email, Company $company): User
    {
        $user = User::factory()->create(['email' => $email]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'E2E-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Бumi',
            'last_name' => 'Тест',
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        return $user;
    }
}
