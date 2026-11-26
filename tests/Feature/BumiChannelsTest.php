<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Ai\Jobs\ProcessBumiChannelMessage;
use App\Domains\Ai\Services\BumiAgentService;
use App\Domains\Ai\Services\BumiAutoReplyService;
use App\Models\BumiAgent;
use App\Models\BumiChannel;
use App\Models\BumiChannelContact;
use App\Models\BumiChannelMessage;
use App\Models\BumiKnowledgeItem;
use App\Models\Company;
use App\Models\CrmLead;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\BumiChannelType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bumi: экраны настроек каналов, приём сообщений, автоответы и изоляция тенантов.
 */
class BumiChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Bumi Co', 'code' => 'bumico']);
        $this->otherCompany = Company::create(['name' => 'Other Co', 'code' => 'otherco']);
        $this->user = $this->makeUser('bumi-owner@corpms.demo', $this->company, 'super_admin');
        $this->otherUser = $this->makeUser('bumi-other@corpms.demo', $this->otherCompany, 'super_admin');

        app(BumiAgentService::class)->ensureDefaults($this->company->id, $this->user->id);

        $this->actingAs($this->user);
    }

    public function test_settings_screens_render_like_velora_agents_with_tabs_and_channels(): void
    {
        $agent = $this->agent();

        $this->get(route('bumi.settings.index'))
            ->assertOk()
            ->assertSee(__('Bumi в чатах'))
            ->assertSee('Bumi')
            ->assertSee('Telegram')
            ->assertSee('Instagram');

        foreach (['settings', 'channels', 'knowledge', 'playground', 'conversations'] as $tab) {
            $this->get(route('bumi.settings.agent', [$agent, 'tab' => $tab]))->assertOk();
        }

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'channels']))
            ->assertOk()
            ->assertSee(__('Webhook URL'))
            ->assertSee(__('Verify Token'));

        $telegram = $this->channel(BumiChannelType::Telegram);
        $this->get(route('bumi.settings.channel', $telegram))
            ->assertOk()
            ->assertSee('Telegram')
            ->assertSee(__('Webhook URL'));

        $website = $this->channel(BumiChannelType::Website);
        $this->get(route('bumi.chat.show', $website))
            ->assertOk()
            ->assertSee('Bumi')
            ->assertSee('bm-assistant__workspace', false)
            ->assertSee(__('Чем помочь?'));
    }

    public function test_settings_are_hidden_without_bumi_permission(): void
    {
        $viewer = $this->makeUser('bumi-viewer@corpms.demo', $this->company, 'employee');
        $this->actingAs($viewer);

        $this->get(route('bumi.settings.index'))->assertForbidden();
        $this->get(route('bumi.settings.agent', $this->agent()))->assertForbidden();
        $this->post(route('bumi.settings.agent.toggle', $this->agent()), ['enabled' => '0'])->assertForbidden();
    }

    public function test_agents_and_channels_of_another_company_are_forbidden(): void
    {
        app(BumiAgentService::class)->ensureDefaults($this->otherCompany->id, $this->otherUser->id);

        $foreignAgent = BumiAgent::query()->where('company_id', $this->otherCompany->id)->firstOrFail();
        $foreignChannel = BumiChannel::query()->where('company_id', $this->otherCompany->id)->firstOrFail();

        $this->get(route('bumi.settings.agent', $foreignAgent))->assertForbidden();
        $this->get(route('bumi.settings.channel', $foreignChannel))->assertForbidden();
        $this->post(route('bumi.settings.channel.toggle', $foreignChannel), ['enabled' => '1'])->assertForbidden();
    }

    public function test_website_message_creates_lead_and_queues_bumi_reply_once(): void
    {
        Queue::fake();
        $channel = $this->channel(BumiChannelType::Website);
        $visitor = 'visitor-key-abcdef123456';

        $response = $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => $visitor,
            'message' => 'Привет, есть ли доставка?',
            'name' => 'Иван',
            'phone' => '+992900123456',
            'client_message_id' => 'msg-1',
        ])->assertCreated()->assertJsonPath('ok', true);

        $messageId = $response->json('message_id');
        $this->assertDatabaseHas('bumi_channel_messages', [
            'id' => $messageId,
            'channel_id' => $channel->id,
            'direction' => 'in',
            'status' => 'queued',
            'external_message_id' => $visitor.':msg-1',
        ]);
        $this->assertDatabaseHas('bumi_channel_contacts', [
            'company_id' => $this->company->id,
            'channel_id' => $channel->id,
            'external_id' => $visitor,
            'display_name' => 'Иван',
        ]);
        $this->assertDatabaseHas('crm_leads', [
            'company_id' => $this->company->id,
            'source' => 'web',
            'contact_name' => 'Иван',
        ]);
        Queue::assertPushed(ProcessBumiChannelMessage::class);

        // Повторная доставка того же сообщения не создаёт дубликат и новый лид.
        $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => $visitor,
            'message' => 'Привет, есть ли доставка?',
            'name' => 'Иван',
            'client_message_id' => 'msg-1',
        ])->assertCreated()->assertJsonPath('message_id', $messageId);

        $this->assertSame(1, BumiChannelContact::query()->where('channel_id', $channel->id)->count());
        $this->assertSame(1, CrmLead::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, BumiChannelMessage::query()->where('channel_id', $channel->id)->where('direction', 'in')->count());

        $this->getJson(route('bumi.channel.messages', $channel).'?visitor='.$visitor)
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.direction', 'in');
    }

    public function test_queued_job_generates_auto_reply_from_bumi_and_marks_message_answered(): void
    {
        Queue::fake();
        $channel = $this->channel(BumiChannelType::Website);
        $response = $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => 'visitor-key-abcdef123456',
            'message' => 'Привет!',
            'client_message_id' => 'msg-2',
        ])->assertCreated();

        $incoming = BumiChannelMessage::query()->findOrFail($response->json('message_id'));
        app()->call([new ProcessBumiChannelMessage($incoming->id), 'handle']);

        $outgoing = BumiChannelMessage::query()->where('reply_to_id', $incoming->id)->firstOrFail();
        $this->assertSame('out', $outgoing->direction->value);
        $this->assertStringContainsString('Bumi', (string) $outgoing->text);
        $this->assertSame('sent', $outgoing->status);
        $this->assertSame('answered', $incoming->fresh()->status);

        // Повторный запуск(job retry) не создаёт второй ответ.
        app()->call([new ProcessBumiChannelMessage($incoming->id), 'handle']);
        $this->assertSame(1, BumiChannelMessage::query()->where('reply_to_id', $incoming->id)->count());

        $this->getJson(route('bumi.channel.messages', $channel).'?visitor=visitor-key-abcdef123456')
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.direction', 'out');
    }

    public function test_telegram_webhook_rejects_requests_without_valid_secret(): void
    {
        $channel = $this->channel(BumiChannelType::Telegram);
        $channel->forceFill([
            'enabled' => true,
            'credentials' => ['bot_token' => '123456:ABCdef', 'webhook_secret' => 'secret-token-123'],
        ])->save();

        $payload = [
            'update_id' => 100,
            'message' => [
                'message_id' => 7,
                'chat' => ['id' => 555],
                'text' => 'Здравствуйте!',
                'from' => ['first_name' => 'Мария'],
            ],
        ];
        $url = route('bumi.channel.webhook.telegram', $channel);

        $this->postJson($url, $payload)->assertForbidden();
        $this->postJson($url, $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        $this->assertSame(0, BumiChannelMessage::query()->where('channel_id', $channel->id)->count());

        Queue::fake();
        $this->postJson($url, $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token-123'])
            ->assertOk()
            ->assertJsonPath('handled', true);

        $this->assertDatabaseHas('bumi_channel_messages', [
            'channel_id' => $channel->id,
            'direction' => 'in',
            'external_message_id' => 'tg:7',
        ]);
        $this->assertDatabaseHas('crm_leads', [
            'company_id' => $this->company->id,
            'source' => 'telegram',
        ]);
        Queue::assertPushed(ProcessBumiChannelMessage::class);
    }

    public function test_meta_verify_challenge_and_signature_protect_instagram_webhook(): void
    {
        $channel = $this->channel(BumiChannelType::Instagram);
        $channel->forceFill([
            'enabled' => true,
            'credentials' => [
                'app_secret' => 'app-secret-1234567890',
                'access_token' => 'token-123456',
                'ig_user_id' => '17841400000000000',
                'verify_token' => 'verify-me-123',
            ],
        ])->save();

        $verifyUrl = route('bumi.channel.webhook.instagram.verify', $channel);
        $this->get($verifyUrl.'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc')->assertForbidden();
        $this->get($verifyUrl.'?hub_mode=subscribe&hub_verify_token=verify-me-123&hub_challenge=abc')
            ->assertOk()
            ->assertJsonPath('challenge', 'abc');

        $payload = json_encode([
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => 'ig_user_1', 'name' => 'Алина'],
                    'message' => ['mid' => 'mid.123', 'text' => 'Здравствуйте!'],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);
        $url = route('bumi.channel.webhook.instagram', $channel);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertForbidden();

        Queue::fake();
        $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'app-secret-1234567890'),
        ], $payload)->assertOk()->assertJsonPath('handled', 1);

        $this->assertDatabaseHas('crm_leads', [
            'company_id' => $this->company->id,
            'source' => 'instagram',
        ]);
        $this->assertDatabaseHas('bumi_channel_contacts', [
            'channel_id' => $channel->id,
            'external_id' => 'ig_user_1',
            'display_name' => 'Алина',
        ]);
    }

    public function test_channel_cannot_be_enabled_without_credentials_and_token_regeneration_changes_webhook(): void
    {
        $channel = $this->channel(BumiChannelType::Telegram);
        $url = route('bumi.settings.channel.update', $channel);

        $this->post($url, [
            'name' => 'Telegram',
            'enabled' => '1',
            'auto_reply' => '1',
        ])->assertSessionHasErrors('enabled');

        $this->assertFalse($channel->fresh()->enabled);

        $this->post($url, [
            'name' => 'Telegram Bumi',
            'enabled' => '1',
            'auto_reply' => '1',
            'credentials' => ['bot_token' => '123456:ABCdef'],
        ])->assertRedirect(route('bumi.settings.channel', $channel));

        $this->assertTrue($channel->fresh()->enabled);
        $this->assertSame('Telegram Bumi', $channel->fresh()->name);

        $oldToken = $channel->public_token;
        $this->post(route('bumi.settings.channel.token', $channel))->assertRedirect();
        $this->assertNotSame($oldToken, $channel->fresh()->public_token);
        $this->getJson(route('bumi.channel.messages', ['channel' => $oldToken]).'?visitor=visitor-key-abcdef123456')->assertNotFound();
    }

    public function test_agent_settings_use_model_select_and_rich_editors(): void
    {
        $agent = $this->agent();
        config()->set('corpms.ai.available_models', ['gpt-4o-mini', 'gpt-4o']);
        config()->set('corpms.ai.model', 'gpt-4o-mini');

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'settings']))
            ->assertOk()
            ->assertSee('<select id="bumi-agent-model"', false)
            ->assertSee('gpt-4o-mini')
            ->assertSee('gpt-4o')
            ->assertSee('bumi-agent-shell__nav', false)
            ->assertSee('data-crm-rich', false)
            ->assertSee('bumi-rich', false)
            ->assertSee('crm-form__row--1', false);

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'knowledge']))
            ->assertOk()
            ->assertSee('data-crm-rich', false)
            ->assertSee('bumi-kb__step', false)
            ->assertSee(__('На чём отвечает ассистент'))
            ->assertSee(__('Общие факты и правила'))
            ->assertSee(__('Файлы и документы'));

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'playground']))
            ->assertOk()
            ->assertSee(__('Сгенерировать вопросы'));

        $this->post(route('bumi.settings.agent.update', $agent), $this->agentPayload(['model' => 'unknown-model']))
            ->assertSessionHasErrors('model');

        $this->post(route('bumi.settings.agent.update', $agent), $this->agentPayload(['model' => 'gpt-4o']))
            ->assertSessionHasNoErrors();
        $this->assertSame('gpt-4o', $agent->fresh()->model);
    }

    public function test_knowledge_pack_qa_and_uploaded_file_are_used_for_replies(): void
    {
        $agent = $this->agent();

        // Готовый пакет уже добавлен при создании агента.
        $this->assertSame(8, BumiKnowledgeItem::query()
            ->where('company_id', $this->company->id)
            ->where('agent_id', $agent->id)
            ->where('kind', 'qa')
            ->count());

        $qa = BumiKnowledgeItem::query()->create([
            'company_id' => $this->company->id,
            'agent_id' => $agent->id,
            'kind' => 'qa',
            'title' => 'Офис',
            'question' => 'Где офис?',
            'answer' => '<p><strong>Офис</strong> на улице Ленина, 12</p>',
            'enabled' => true,
        ]);

        $this->post(route('bumi.settings.knowledge.item.update', $qa), [
            'qa_id' => $qa->id,
            'title' => 'Наш офис',
            'question' => 'Где находится офис?',
            'answer' => '<p><strong>Офис</strong> на улице Ленина, 12</p>',
        ])->assertRedirect(route('bumi.settings.agent', [$agent, 'tab' => 'knowledge']));
        $this->assertSame('Наш офис', $qa->fresh()->title);

        $reply = app(BumiAutoReplyService::class)->reply($agent, 'Где офис?');
        $this->assertStringContainsString('Офис на улице Ленина, 12', $reply);
        $this->assertStringNotContainsString('<p>', $reply);

        Storage::fake('local');
        $this->post(route('bumi.settings.knowledge.upload', $agent), [
            'title' => 'Условия доставки',
            'file' => UploadedFile::fake()->createWithContent('delivery.txt', 'Доставка по городу занимает два дня.'),
        ])->assertRedirect(route('bumi.settings.agent', [$agent, 'tab' => 'knowledge']));

        $file = BumiKnowledgeItem::query()
            ->where('agent_id', $agent->id)
            ->where('kind', 'file')
            ->firstOrFail();
        $this->assertSame('delivery.txt', $file->filename);
        $this->assertNotNull($file->meta['document_id'] ?? null);

        $reply = app(BumiAutoReplyService::class)->reply($agent, 'Когда доставка по городу?');
        $this->assertStringContainsString('Доставка по городу занимает два дня', $reply);
    }

    public function test_playground_generates_questions_and_conversations_can_be_filtered(): void
    {
        $agent = $this->agent();
        $channel = $this->channel(BumiChannelType::Website);

        $this->post(route('bumi.settings.playground.generate', $agent))
            ->assertRedirect(route('bumi.settings.agent', [$agent, 'tab' => 'playground']))
            ->assertSessionHas('bumi_generated_questions');
        $this->assertGreaterThanOrEqual(3, count(session('bumi_generated_questions')));

        Queue::fake();
        $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => 'visitor-key-filter-test',
            'message' => 'Интересует доставка',
            'client_message_id' => 'filter-msg-1',
        ])->assertCreated();

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'conversations', 'channel' => 'telegram', 'view' => 'cards']))
            ->assertOk()
            ->assertDontSee('Интересует доставка');

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'conversations', 'channel' => 'website', 'view' => 'cards']))
            ->assertOk()
            ->assertSee('Интересует доставка')
            ->assertSee('Карточки');
    }

    public function test_website_history_supports_since_and_echoes_client_message_id(): void
    {
        Queue::fake();
        $channel = $this->channel(BumiChannelType::Website);
        $visitor = 'visitor-key-since-12345';
        $url = route('bumi.channel.messages.store', $channel);

        $first = $this->postJson($url, [
            'visitor' => $visitor,
            'message' => 'Первый вопрос',
            'client_message_id' => 'since-1',
        ])->assertCreated();
        $second = $this->postJson($url, [
            'visitor' => $visitor,
            'message' => 'Второй вопрос',
            'client_message_id' => 'since-2',
        ])->assertCreated();

        $this->assertSame('since-1', $first->json('client_message_id'));
        $this->assertSame('queued', $first->json('status'));
        $this->assertSame('В очереди', $first->json('status_label'));

        // Повтор с тем же client_message_id не создаёт вторую запись.
        $this->postJson($url, [
            'visitor' => $visitor,
            'message' => 'Первый вопрос',
            'client_message_id' => 'since-1',
        ])->assertCreated()->assertJsonPath('message_id', $first->json('message_id'));

        $this->getJson(route('bumi.channel.messages', $channel).'?visitor='.$visitor.'&since='.$first->json('message_id'))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $second->json('message_id'))
            ->assertJsonPath('messages.0.client_message_id', 'since-2')
            ->assertJsonPath('messages.0.status_label', 'В очереди');
    }

    public function test_toggle_endpoints_return_json_state_and_undo_payload(): void
    {
        $agent = $this->agent();
        $channel = $this->channel(BumiChannelType::Website);
        $item = BumiKnowledgeItem::query()->create([
            'company_id' => $this->company->id,
            'agent_id' => $agent->id,
            'kind' => 'qa',
            'title' => 'Режим JSON',
            'question' => 'Включено?',
            'answer' => 'Да',
            'enabled' => true,
        ]);

        $agentResponse = $this->postJson(route('bumi.settings.agent.toggle', $agent), ['enabled' => 0])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('kind', 'agent')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('label', 'На паузе')
            ->assertJsonPath('undo_enabled', true);
        $this->assertNotEmpty($agentResponse->json('undo_url'));
        $this->assertFalse($agent->fresh()->enabled);

        $channelResponse = $this->postJson(route('bumi.settings.channel.toggle', $channel), ['enabled' => 0])
            ->assertOk()
            ->assertJsonPath('kind', 'channel')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('class', 'crm-chip--grey')
            ->assertJsonPath('undo_enabled', true);
        $this->assertNotEmpty($channelResponse->json('undo_url'));
        $this->assertFalse($channel->fresh()->enabled);

        $this->postJson(route('bumi.settings.channel.toggle', $channel), ['enabled' => 1])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('undo_enabled', false);
        $this->assertTrue($channel->fresh()->enabled);

        $this->postJson(route('bumi.settings.knowledge.item.toggle', $item))
            ->assertOk()
            ->assertJsonPath('kind', 'knowledge')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('undo_enabled', true);
        $this->assertFalse($item->fresh()->enabled);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bumi.agent_toggled',
            'company_id' => $this->company->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bumi.channel_toggled',
            'company_id' => $this->company->id,
        ]);

        $viewer = $this->makeUser('bumi-json-viewer@corpms.demo', $this->company, 'employee');
        $this->actingAs($viewer);
        $this->postJson(route('bumi.settings.agent.toggle', $agent), ['enabled' => 1])->assertForbidden();
        $this->postJson(route('bumi.settings.channel.toggle', $channel), ['enabled' => 0])->assertForbidden();
    }

    public function test_retry_returns_json_effects_and_keeps_redirect_fallback(): void
    {
        Queue::fake();
        $channel = $this->channel(BumiChannelType::Website);
        $created = $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => 'visitor-key-retry-12345',
            'message' => 'Вопрос для повтора',
            'client_message_id' => 'retry-1',
        ])->assertCreated();

        $incoming = BumiChannelMessage::query()->findOrFail($created->json('message_id'));
        $incoming->forceFill(['status' => 'delivery_failed'])->save();
        $reply = BumiChannelMessage::query()->create([
            'company_id' => $this->company->id,
            'channel_id' => $channel->id,
            'agent_id' => $incoming->agent_id,
            'contact_id' => $incoming->contact_id,
            'direction' => 'out',
            'text' => 'Устаревший ответ',
            'status' => 'failed',
            'reply_to_id' => $incoming->id,
        ]);

        Queue::fake();
        $response = $this->postJson(route('bumi.settings.message.retry', $incoming))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message_id', $incoming->id)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('status_label', 'В очереди')
            ->assertJsonPath('status_class', 'crm-chip--grey')
            ->assertJsonPath('effects.0.kind', 'message')
            ->assertJsonPath('already_queued', false);
        $this->assertContains($reply->id, $response->json('removed_reply_ids'));
        $this->assertSame('queued', $incoming->fresh()->status);
        $this->assertNull(BumiChannelMessage::query()->find($reply->id));
        Queue::assertPushed(ProcessBumiChannelMessage::class, 1);

        $this->postJson(route('bumi.settings.message.retry', $incoming))
            ->assertOk()
            ->assertJsonPath('already_queued', true)
            ->assertJsonPath('removed_reply_ids', []);
        Queue::assertPushed(ProcessBumiChannelMessage::class, 1);

        $this->post(route('bumi.settings.message.retry', $incoming))->assertRedirect();
    }

    public function test_conversations_show_honest_status_labels_instead_of_raw_values(): void
    {
        Queue::fake();
        $agent = $this->agent();
        $channel = $this->channel(BumiChannelType::Website);
        $created = $this->postJson(route('bumi.channel.messages.store', $channel), [
            'visitor' => 'visitor-key-status-12345',
            'message' => 'Вопрос про статусы',
            'client_message_id' => 'status-1',
        ])->assertCreated();
        $seed = BumiChannelMessage::query()->findOrFail($created->json('message_id'));

        foreach (['rate_limited', 'failed', 'delivery_failed', 'queued'] as $index => $status) {
            BumiChannelMessage::query()->create([
                'company_id' => $this->company->id,
                'channel_id' => $channel->id,
                'agent_id' => $seed->agent_id,
                'contact_id' => $seed->contact_id,
                'external_message_id' => 'status-test-'.$index,
                'direction' => 'in',
                'text' => 'Статус '.$status,
                'status' => $status,
            ]);
        }

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'conversations', 'channel' => 'website', 'view' => 'cards']))
            ->assertOk()
            ->assertSee('Лимит ответов')
            ->assertSee('Ошибка доставки')
            ->assertSee('Ответ не доставлен')
            ->assertSee('В очереди')
            ->assertDontSee('>delivery_failed<', false)
            ->assertDontSee('>rate_limited<', false);
    }

    public function test_bumi_settings_pages_do_not_load_lms_or_realtime_assets(): void
    {
        $agent = $this->agent();
        $urls = [
            route('bumi.settings.index'),
            route('bumi.settings.agent', [$agent, 'tab' => 'settings']),
            route('bumi.settings.channel', $this->channel(BumiChannelType::Website)),
        ];

        foreach ($urls as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('/assets/js/bumi-settings-ux.js', false)
                ->assertDontSee('/assets/js/lms.js', false)
                ->assertDontSee('/assets/css/lms.css', false)
                ->assertDontSee('/assets/vendor/echo/pusher.min.js', false)
                ->assertDontSee('/assets/vendor/echo/echo.iife.js', false)
                ->assertDontSee('window.LMS_I18N', false);
        }
    }

    public function test_in_place_wiring_and_playground_json_are_present(): void
    {
        $agent = $this->agent();

        $this->get(route('bumi.settings.index'))
            ->assertOk()
            ->assertSee('data-bumi-toggle', false)
            ->assertSee('data-bumi-toggle-chip', false)
            ->assertSee('/assets/js/bumi-settings-ux.js', false);

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'channels']))
            ->assertOk()
            ->assertSee('data-bumi-toggle', false);
        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'knowledge']))
            ->assertOk()
            ->assertSee('data-bumi-toggle', false);
        Queue::fake();
        $this->postJson(route('bumi.channel.messages.store', $this->channel(BumiChannelType::Website)), [
            'visitor' => 'visitor-key-wiring-12345',
            'message' => 'Проверка разметки',
            'client_message_id' => 'wiring-1',
        ])->assertCreated();

        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'conversations']))
            ->assertOk()
            ->assertSee('data-bumi-retry', false);
        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'playground']))
            ->assertOk()
            ->assertSee('data-bumi-playground-form', false)
            ->assertSee('bumi-playground-thread', false);
        $this->get(route('bumi.chat.show', $this->channel(BumiChannelType::Website)))
            ->assertOk()
            ->assertSee('data-bumi-public-workspace', false)
            ->assertSee('client_message_id', false)
            ->assertSee('is-typing', false);

        $playground = $this->postJson(route('bumi.settings.agent.playground', $agent), ['message' => 'Где офис?'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Где офис?');
        $this->assertNotSame('', $playground->json('reply'));
    }

    public function test_playground_exposes_honest_source_and_status_effects(): void
    {
        $agent = $this->agent();

        $playground = $this->postJson(route('bumi.settings.agent.playground', $agent), ['message' => 'привет'])
            ->assertOk()
            ->assertJsonPath('source', 'greeting')
            ->assertJsonPath('message', 'привет')
            ->assertJsonStructure(['reply', 'source', 'source_label', 'source_title']);
        $this->assertNotSame('', $playground->json('source_label'));

        $this->post(route('bumi.settings.agent.playground', $agent), ['message' => 'привет'])
            ->assertRedirect()
            ->assertSessionHas('bumi_playground_source');
        $this->get(route('bumi.settings.agent', [$agent, 'tab' => 'playground']))
            ->assertOk()
            ->assertSee('data-bumi-playground-source', false);

        $channel = $this->channel(BumiChannelType::Website);
        $wasConnected = $channel->enabled && $channel->isConfigured();
        $toggle = $this->postJson(route('bumi.settings.channel.toggle', $channel), ['enabled' => 0])
            ->assertOk()
            ->assertJsonPath('effects.0.kind', 'channel')
            ->assertJsonPath('was_connected', $wasConnected)
            ->assertJsonPath('connected', false)
            ->assertJsonPath('counts.connected', 0);
        $this->assertIsInt($toggle->json('counts.connected'));
    }

    public function test_knowledge_delete_supports_in_place_undo_with_tenant_guard(): void
    {
        $agent = $this->agent();
        $baseQuery = BumiKnowledgeItem::query()
            ->where('company_id', $this->company->id)
            ->where('agent_id', $agent->id)
            ->where('kind', 'qa');
        $baseQa = (clone $baseQuery)->count();
        $baseActive = (clone $baseQuery)->where('enabled', true)->count();

        $item = BumiKnowledgeItem::query()->create([
            'company_id' => $this->company->id,
            'agent_id' => $agent->id,
            'kind' => 'qa',
            'title' => 'Удаляемый ответ',
            'question' => 'Удалить?',
            'answer' => 'Да, но можно вернуть',
            'enabled' => true,
        ]);

        $deleted = $this->postJson(route('bumi.settings.knowledge.item.delete', $item))
            ->assertOk()
            ->assertJsonPath('kind', 'knowledge_delete')
            ->assertJsonPath('effects.0.kind', 'knowledge_delete')
            ->assertJsonPath('counts.qa_active', $baseActive);
        $this->assertDatabaseMissing('bumi_knowledge_items', ['id' => $item->id]);

        $undoUrl = (string) $deleted->json('undo_url');
        $this->assertNotEmpty($undoUrl);

        $restored = $this->postJson($undoUrl)
            ->assertOk()
            ->assertJsonPath('kind', 'knowledge_restore')
            ->assertJsonPath('effects.0.title', 'Удаляемый ответ')
            ->assertJsonPath('counts.qa', $baseQa + 1);
        $this->assertDatabaseHas('bumi_knowledge_items', [
            'id' => $item->id,
            'company_id' => $this->company->id,
            'agent_id' => $agent->id,
            'title' => 'Удаляемый ответ',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bumi.knowledge_item_restored',
            'company_id' => $this->company->id,
        ]);

        $this->postJson($undoUrl)->assertNotFound();

        $second = BumiKnowledgeItem::query()->create([
            'company_id' => $this->company->id,
            'agent_id' => $agent->id,
            'kind' => 'qa',
            'title' => 'Чужой откат',
            'question' => 'Чей откат?',
            'answer' => 'Только своей компании',
            'enabled' => true,
        ]);
        $foreignUndoUrl = (string) $this->postJson(route('bumi.settings.knowledge.item.delete', $second))
            ->assertOk()
            ->json('undo_url');
        $this->actingAs($this->otherUser)->postJson($foreignUndoUrl)->assertForbidden();
        $this->assertDatabaseMissing('bumi_knowledge_items', ['id' => $second->id]);
    }

    private function agent(): BumiAgent
    {
        return BumiAgent::query()->where('company_id', $this->company->id)->firstOrFail();
    }

    private function channel(BumiChannelType $type): BumiChannel
    {
        return BumiChannel::query()
            ->where('company_id', $this->company->id)
            ->where('type', $type->value)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function agentPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Bumi',
            'description' => '<p>Основной ассистент</p>',
            'system_prompt' => 'Ты — Bumi, ассистент компании.',
            'knowledge' => 'Доставка: 1–2 дня',
            'greeting' => 'Здравствуйте! Я Bumi.',
            'fallback_reply' => 'Уточните вопрос.',
            'model' => '',
            'enabled' => '1',
            'auto_reply' => '1',
            'create_leads' => '1',
            'max_replies_per_hour' => '20',
            'default_owner_id' => '',
        ], $overrides);
    }

    private function makeUser(string $email, Company $company, string $roleSlug): User
    {
        $user = User::factory()->create(['email' => $email]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => 'B-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Бumi',
            'last_name' => 'Тест',
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);

        return $user;
    }
}
