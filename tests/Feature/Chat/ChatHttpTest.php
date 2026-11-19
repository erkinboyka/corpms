<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BM Chat: отправка, идемпотентность, права на диалог, межтенантная
 * изоляция, непрочитанные и журнал дельт.
 *
 * Источник истины — база: каждый сценарий проверяется по записи в БД,
 * а не по ответу UI. Broadcast отключён (null-драйвер в phpunit.xml),
 * поэтому тесты не зависят от realtime-транспорта.
 */
class ChatHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected Employee $adminEmployeeA;

    protected Employee $workerA;

    protected Employee $workerB;

    protected User $workerUserB;

    protected Conversation $direct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->companyA = Company::create(['name' => 'Chat A', 'code' => 'chat-a']);
        $this->companyB = Company::create(['name' => 'Chat B', 'code' => 'chat-b']);

        $superAdminRoleId = Role::query()->where('slug', 'super_admin')->value('id');

        $this->adminA = User::factory()->create(['email' => 'chat-admin@tenant.test']);
        $this->adminEmployeeA = $this->employee($this->companyA, 'CA-1', 'Админ', 'Чата', $this->adminA->id);
        EmployeeRole::create(['employee_id' => $this->adminEmployeeA->id, 'role_id' => $superAdminRoleId]);

        $this->workerA = $this->employee($this->companyA, 'CA-2', 'Работник', 'А');
        $this->workerB = $this->employee($this->companyB, 'CB-1', 'Работник', 'Б');
        $this->workerUserB = User::findOrFail($this->workerB->user_id);

        $this->direct = app(\App\Domains\Chat\Services\ChatService::class)
            ->findOrCreateDirect($this->adminEmployeeA, $this->workerA);

        $this->actingAs($this->adminA);
    }

    /**
     * @param  string|null  $roleSlug  роль из RbacSeeder; null — без роли
     *                                 (middleware permission:* тогда откажет)
     */
    protected function employee(Company $company, string $no, string $first, string $last, ?int $userId = null, ?string $roleSlug = 'employee'): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);

        if ($roleSlug !== null) {
            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            ]);
        }

        return $employee;
    }

    /** Назначить роль из RBAC-сида (без неё middleware permission:* откажет). */
    protected function assignRole(Employee $employee, string $slug): void
    {
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    /**
     * Канальная авторизация проверяется на pusher-совместимом драйвере:
     * phpunit переключает BROADCAST_CONNECTION на null, а NullBroadcaster
     * пропускает любой канал. Ключи фиктивные — подпись считается локально,
     * сетевых вызовов нет.
     */
    protected function useRealChannelAuth(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options' => [
                'host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false,
            ],
        ]);

        // Каналы регистрируются на драйвере, который был активен при загрузке
        // routes/channels.php (в phpunit это null). После смены драйвера
        // экземпляр новый — регистрируем каналы на нём заново.
        app(\Illuminate\Contracts\Broadcasting\Factory::class)->forgetDrivers();
        require base_path('routes/channels.php');
    }

    /* ---------- отправка ---------- */

    public function test_group_owner_can_update_invite_exclude_and_delete_conversation(): void
    {
        $group = app(\App\Domains\Chat\Services\ChatService::class)
            ->createGroup($this->adminEmployeeA, 'Группа', [$this->workerA->id]);
        $url = '/chat/'.$group->id;
        $invitee = $this->employee($this->companyA, 'CA-3', 'Иван', 'Иванов');

        $this->getJson('/chat/employees?'.http_build_query(['conversation' => $group->id, 'q' => 'Иванов Иван']))
            ->assertOk()->assertJsonPath('employees.0.id', $invitee->id);
        $this->patch($url, ['title' => 'Команда', 'description' => 'Обсуждение проекта'])->assertRedirect($url);
        $this->assertDatabaseHas('conversations', ['id' => $group->id, 'title' => 'Команда']);
        $this->postJson($url.'/members', ['member_ids' => [$this->workerB->id]])->assertUnprocessable();
        $this->post($url.'/members', ['member_ids' => [$invitee->id]])->assertRedirect($url);
        $this->actingAs($invitee->user)->getJson($url.'/messages')->assertOk();
        $this->delete($url)->assertForbidden();
        $this->actingAs($this->adminA)->delete($url.'/members/'.$invitee->id)->assertRedirect($url);
        $this->actingAs($invitee->user)->getJson($url.'/messages')->assertNotFound();
        $this->getJson($url.'/events')->assertNotFound();
        $this->actingAs($this->adminA)->post($url.'/members', ['member_ids' => [$invitee->id]])->assertRedirect($url);
        $this->actingAs($invitee->user)->getJson($url.'/messages')->assertOk();
        $this->actingAs($this->adminA)->delete($url)->assertRedirect('/chat');
        $this->assertSoftDeleted($group);
        $this->actingAs($this->workerA->user)->get($url)->assertNotFound();
    }

    public function test_group_photo_is_member_only_and_wallpaper_is_personal(): void
    {
        Storage::fake('local');
        $group = app(\App\Domains\Chat\Services\ChatService::class)
            ->createGroup($this->adminEmployeeA, 'Группа', [$this->workerA->id]);
        $url = '/chat/'.$group->id;
        $this->post($url, ['_method' => 'PATCH', 'title' => 'Группа', 'avatar' => UploadedFile::fake()->image('photo.png')])
            ->assertRedirect($url);
        Storage::disk('local')->assertExists($group->fresh()->avatar_path);
        $this->post($url.'/wallpaper', ['_method' => 'PATCH', 'wallpaper' => UploadedFile::fake()->image('background.png')])
            ->assertRedirect($url);
        $this->get($url.'/image/wallpaper')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->workerA->user)->get($url.'/image/avatar')->assertOk();
        $this->get($url.'/image/wallpaper')->assertNotFound();
        $this->actingAs($this->workerUserB)->get($url.'/image/avatar')->assertForbidden();
        $this->actingAs($this->adminA)->patch($url.'/wallpaper', ['reset_wallpaper' => '1'])->assertRedirect($url);
        $this->get($url.'/image/wallpaper')->assertNotFound();
    }

    public function test_hiding_direct_conversation_does_not_remove_it_for_other_employee(): void
    {
        $url = '/chat/'.$this->direct->id;
        $this->delete($url)->assertRedirect('/chat');
        $this->assertModelExists($this->direct);
        $this->get('/chat')->assertDontSee('data-id="'.$this->direct->id.'"', false);
        $this->actingAs($this->workerA->user)->get('/chat')->assertSee('data-id="'.$this->direct->id.'"', false);
        $this->actingAs($this->adminA)->post('/chat/conversations', ['type' => 'direct', 'employee_id' => $this->workerA->id])
            ->assertRedirect($url);
        $this->get('/chat')->assertSee('data-id="'.$this->direct->id.'"', false);
    }

    public function test_reply_quote_is_included_in_send_retry_edit_and_events(): void
    {
        $url = '/chat/'.$this->direct->id;
        $originalId = $this->postJson($url.'/messages', ['body' => 'Оригинал'])
            ->assertCreated()
            ->json('message.id');
        $draft = ['body' => 'Ответ', 'reply_to_id' => $originalId, 'client_id' => 'reply-draft-1'];
        $replyId = $this->postJson($url.'/messages', $draft)
            ->assertCreated()
            ->assertJsonPath('message.reply_to.id', $originalId)
            ->assertJsonPath('message.reply_to.body', 'Оригинал')
            ->json('message.id');

        $this->postJson($url.'/messages', $draft)
            ->assertCreated()
            ->assertJsonPath('message.id', $replyId)
            ->assertJsonPath('message.reply_to.id', $originalId);
        $this->putJson('/chat/messages/'.$replyId, ['body' => 'Обновлённый ответ'])
            ->assertOk()
            ->assertJsonPath('message.reply_to.id', $originalId);

        $events = collect($this->getJson($url.'/events?since=0')->assertOk()->json('events'));
        $replyEvents = $events->where('message.id', $replyId)->values();
        self::assertCount(2, $replyEvents);
        foreach ($replyEvents as $event) {
            self::assertSame($originalId, data_get($event, 'message.reply_to.id'));
        }

        $this->get($url)->assertOk()->assertSee('data-reply-to="'.$originalId.'"', false);
    }

    public function test_send_stores_message_and_records_event(): void
    {
        $response = $this->postJson('/chat/'.$this->direct->id.'/messages', [
            'body' => 'Привет, коллега',
            'client_id' => 'client-a1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message.body', 'Привет, коллега')
            ->assertJsonPath('message.employee_id', $this->adminEmployeeA->id);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->direct->id,
            'employee_id' => $this->adminEmployeeA->id,
            'body' => 'Привет, коллега',
            'client_id' => 'client-a1',
        ]);

        $this->assertSame(1, MessageEvent::query()
            ->where('conversation_id', $this->direct->id)
            ->where('type', 'created')
            ->count());

        // граница последнего сообщения поднята — лента считает непрочитанные
        $this->assertSame(1, Message::query()->where('conversation_id', $this->direct->id)->count());
    }

    public function test_send_is_idempotent_for_same_client_id(): void
    {
        $payload = ['body' => 'Один раз', 'client_id' => 'same-key'];

        $first = $this->postJson('/chat/'.$this->direct->id.'/messages', $payload);
        $second = $this->postJson('/chat/'.$this->direct->id.'/messages', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);

        $this->assertSame(
            $first->json('message.id'),
            $second->json('message.id'),
            'Повтор того же client_id должен вернуть уже сохранённое сообщение'
        );

        $this->assertSame(1, Message::query()
            ->where('conversation_id', $this->direct->id)
            ->where('client_id', 'same-key')
            ->count());
    }

    public function test_send_rejects_empty_body(): void
    {
        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, Message::query()->where('conversation_id', $this->direct->id)->count());
    }

    public function test_send_requires_chat_send_permission(): void
    {
        $restricted = User::factory()->create(['email' => 'chat-viewer@tenant.test']);
        // без роли — права chat.send нет, middleware permission:chat.send откажет
        $this->employee($this->companyA, 'CA-3', 'Только', 'Чтение', $restricted->id, null);

        $this->actingAs($restricted)
            ->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'нет прав'])
            ->assertForbidden();

        $this->assertSame(0, Message::query()->where('conversation_id', $this->direct->id)->count());
    }

    /* ---------- права на диалог ---------- */

    public function test_non_member_cannot_read_private_conversation(): void
    {
        $stranger = $this->employee($this->companyA, 'CA-9', 'Посторонний', 'Сотрудник');
        $strangerUser = User::findOrFail($stranger->user_id);

        $this->actingAs($strangerUser)
            ->getJson('/chat/'.$this->direct->id.'/events?since=0')
            ->assertNotFound();

        $this->actingAs($strangerUser)
            ->getJson('/chat/'.$this->direct->id.'/messages')
            ->assertNotFound();

        $this->actingAs($strangerUser)
            ->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'взлом'])
            ->assertNotFound();
    }

    public function test_conversation_of_other_company_is_invisible(): void
    {
        $foreign = app(\App\Domains\Chat\Services\ChatService::class)
            ->findOrCreateDirect($this->workerB, $this->employee($this->companyB, 'CB-2', 'Второй', 'Б'));

        // Конвенция проекта (EnsureTenantIsolation): объект чужого тенанта — 403,
        // чужой неприватный диалог своей компании — 404.
        $this->getJson('/chat/'.$foreign->id.'/messages')->assertForbidden();
        $this->postJson('/chat/'.$foreign->id.'/messages', ['body' => 'чужой'])->assertForbidden();
        $this->get('/chat/'.$foreign->id)->assertForbidden();
    }

    public function test_company_channel_is_readable_without_membership(): void
    {
        $channel = app(\App\Domains\Chat\Services\ChatService::class)->createChannel($this->adminEmployeeA, [
            'title' => 'Объявления',
            'visibility' => 'company',
            'post_permission' => 'all',
        ]);

        $subscriber = $this->employee($this->companyA, 'CA-4', 'Подписчик', 'Канала');
        $subscriberUser = User::findOrFail($subscriber->user_id);

        $this->actingAs($subscriberUser)
            ->getJson('/chat/'.$channel->id.'/messages')
            ->assertOk();

        // но писать может только с правом chat.send — проверяем само право
        $this->actingAs($subscriberUser)
            ->postJson('/chat/'.$channel->id.'/messages', ['body' => 'сообщение в канал'])
            ->assertCreated();

        $this->assertSame(1, Message::query()->where('conversation_id', $channel->id)->count());
    }

    public function test_private_channel_is_invisible_to_non_member(): void
    {
        $channel = app(\App\Domains\Chat\Services\ChatService::class)->createChannel($this->adminEmployeeA, [
            'title' => 'Закрытый',
            'visibility' => 'private',
            'post_permission' => 'admins',
            'member_ids' => [$this->adminEmployeeA->id],
        ]);

        $outsider = $this->employee($this->companyA, 'CA-5', 'Не', 'Участник');
        $outsiderUser = User::findOrFail($outsider->user_id);

        $this->actingAs($outsiderUser)
            ->getJson('/chat/'.$channel->id.'/messages')
            ->assertNotFound();
    }

    /* ---------- непрочитанные ---------- */

    public function test_unread_count_moves_only_forward_on_read(): void
    {
        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'первое']);
        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'второе']);

        $unread = $this->getJson('/chat/'.$this->direct->id.'/events?since=0')->json('unread_count');
        $this->assertSame(0, $unread, 'Свои сообщения не должны считаться непрочитанными');

        // читает собеседник
        $peerUser = User::findOrFail($this->workerA->user_id);
        $peerUnread = $this->actingAs($peerUser)
            ->getJson('/chat/'.$this->direct->id.'/events?since=0')
            ->json('unread_count');
        $this->assertSame(2, $peerUnread);

        $this->actingAs($peerUser)
            ->postJson('/chat/'.$this->direct->id.'/read', [])
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        // устаревший запрос не «недочитывает» ленту
        $this->actingAs($peerUser)
            ->postJson('/chat/'.$this->direct->id.'/read', ['up_to_message_id' => 1])
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $this->actingAs($peerUser)
            ->getJson('/chat/'.$this->direct->id.'/events?since=0')
            ->json('unread_count'));
    }

    public function test_unread_summary_returns_per_conversation_counts(): void
    {
        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'для собеседника']);

        $peerUser = User::findOrFail($this->workerA->user_id);
        $body = $this->actingAs($peerUser)->getJson('/chat/unread')->json();

        $row = collect($body['conversations'])->firstWhere('id', $this->direct->id);

        $this->assertNotNull($row, 'Диалог должен попасть в сводку непрочитанных');
        $this->assertSame(1, $row['unread_count']);
        $this->assertSame(1, $body['total']);
    }

    /* ---------- журнал дельт ---------- */

    public function test_events_endpoint_returns_deltas_after_cursor(): void
    {
        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'до курсора', 'client_id' => 'e1']);

        $firstPage = $this->getJson('/chat/'.$this->direct->id.'/events?since=0')->json();
        $this->assertNotEmpty($firstPage['events']);
        $cursor = $firstPage['last_event_id'];

        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'после курсора', 'client_id' => 'e2']);

        $secondPage = $this->getJson('/chat/'.$this->direct->id.'/events?since='.$cursor)->json();

        $this->assertCount(1, $secondPage['events'], 'После курсора должно прийти ровно одно новое событие');
        $this->assertSame('created', $secondPage['events'][0]['type']);
        $this->assertSame('после курсора', $secondPage['events'][0]['message']['body']);
        $this->assertGreaterThan($cursor, $secondPage['last_event_id']);
    }

    public function test_events_are_ordered_and_limited_by_conversation(): void
    {
        $other = app(\App\Domains\Chat\Services\ChatService::class)
            ->findOrCreateDirect($this->adminEmployeeA, $this->employee($this->companyA, 'CA-7', 'Другой', 'Собеседник'));

        $this->postJson('/chat/'.$other->id.'/messages', ['body' => 'в другом диалоге', 'client_id' => 'x1']);
        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'в этом', 'client_id' => 'x2']);

        $events = $this->getJson('/chat/'.$this->direct->id.'/events?since=0')->json('events');

        foreach ($events as $event) {
            $this->assertSame($this->direct->id, $event['conversation_id']);
        }

        $ids = collect($events)->pluck('id');
        $this->assertSame(
            $ids->sort()->values()->all(),
            $ids->all(),
            'События должны идти по возрастанию id'
        );
    }

    public function test_edit_and_delete_produce_events_and_change_body(): void
    {
        $id = $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'черновик', 'client_id' => 'd1'])
            ->json('message.id');

        $this->putJson('/chat/messages/'.$id, ['body' => 'исправлено'])
            ->assertOk()
            ->assertJsonPath('message.body', 'исправлено');

        $this->assertDatabaseHas('messages', ['id' => $id, 'body' => 'исправлено']);
        $this->assertNotNull(Message::query()->find($id)->edited_at);

        $this->deleteJson('/chat/messages/'.$id)->assertOk();

        $this->assertSoftDeleted('messages', ['id' => $id]);

        $types = MessageEvent::query()
            ->where('conversation_id', $this->direct->id)
            ->orderBy('id')
            ->pluck('type')
            ->map(static fn (mixed $type): string => $type instanceof \BackedEnum ? $type->value : (string) $type)
            ->all();

        $this->assertContains('updated', $types);
        $this->assertContains('deleted', $types);
    }

    public function test_author_cannot_edit_foreign_message(): void
    {
        $peerUser = User::findOrFail($this->workerA->user_id);
        $foreignId = $this->actingAs($peerUser)
            ->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'чужое'])
            ->json('message.id');

        $this->actingAs($this->adminA)
            ->putJson('/chat/messages/'.$foreignId, ['body' => 'взлом'])->assertForbidden();

        $this->assertDatabaseHas('messages', ['id' => $foreignId, 'body' => 'чужое']);
    }

    /* ---------- страница ---------- */

    public function test_chat_page_renders_active_conversation_and_last_event_cursor(): void
    {
        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'видно на странице']);

        $html = $this->get('/chat/'.$this->direct->id)->assertOk()->getContent();

        $this->assertStringContainsString('видно на странице', $html);
        $this->assertStringContainsString('data-last-event-id="'.MessageEvent::query()
            ->where('conversation_id', $this->direct->id)
            ->max('id').'"', $html);
        $this->assertStringContainsString('data-can-post="1"', $html);
    }

    public function test_chat_page_is_forbidden_without_permission(): void
    {
        $noPermission = User::factory()->create(['email' => 'chat-none@tenant.test']);
        $this->employee($this->companyA, 'CA-8', 'Без', 'Прав', $noPermission->id, null);

        // без роли права нет — middleware permission:chat.view должен отказать
        $this->actingAs($noPermission)->get('/chat')->assertForbidden();
    }

    /* ---------- журнал событий и подписки ---------- */

    public function test_subscription_toggle_only_for_channels(): void
    {
        $this->postJson('/chat/'.$this->direct->id.'/subscribe', ['subscribe' => true])
            ->assertForbidden();

        $channel = app(\App\Domains\Chat\Services\ChatService::class)->createChannel($this->adminEmployeeA, [
            'title' => 'Новости',
            'visibility' => 'company',
            'post_permission' => 'all',
        ]);

        $this->postJson('/chat/'.$channel->id.'/subscribe', ['subscribe' => false])
            ->assertOk()
            ->assertJsonPath('subscribed', false);
    }

    public function test_reactions_toggle_and_are_reported_in_events(): void
    {
        $id = $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'реакция?'])
            ->json('message.id');

        $this->postJson('/chat/messages/'.$id.'/reactions', ['emoji' => '👍', 'on' => true])
            ->assertOk()
            ->assertJsonCount(1, 'reactions');

        $this->postJson('/chat/messages/'.$id.'/reactions', ['emoji' => '👍', 'on' => false])
            ->assertOk()
            ->assertJsonCount(0, 'reactions');

        $this->assertSame(2, MessageEvent::query()
            ->where('conversation_id', $this->direct->id)
            ->where('type', 'reaction')
            ->count());
    }

    /* ---------- авторизация broadcast-каналов ---------- */

    public function test_broadcast_channel_denies_foreign_and_allows_member(): void
    {
        $this->useRealChannelAuth();

        $channelName = 'chat.conversation.'.$this->direct->id;

        $memberOk = $this->actingAs($this->adminA)
            ->postJson('/broadcasting/auth', ['channel_name' => $channelName, 'socket_id' => '1.1']);
        $memberOk->assertOk();

        // сотрудник компании A, не являющийся участником приватного диалога
        $outsider = $this->employee($this->companyA, 'CA-6', 'Не', 'В Диалоге');
        $outsiderUser = User::findOrFail($outsider->user_id);

        $this->actingAs($outsiderUser)
            ->postJson('/broadcasting/auth', ['channel_name' => $channelName, 'socket_id' => '1.1'])
            ->assertForbidden();

        // сотрудник другой компании
        $this->actingAs($this->workerUserB)
            ->postJson('/broadcasting/auth', ['channel_name' => $channelName, 'socket_id' => '1.1'])
            ->assertForbidden();
    }

    public function test_personal_notice_channel_is_scoped_to_owner(): void
    {
        $this->useRealChannelAuth();

        $ownerChannel = 'employee.'.$this->adminEmployeeA->id.'.chat';

        $this->postJson('/broadcasting/auth', ['channel_name' => $ownerChannel, 'socket_id' => '1.1'])
            ->assertOk();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'employee.'.$this->workerA->id.'.chat',
            'socket_id' => '1.1',
        ])->assertForbidden();
    }

    /* ---------- realtime-события не ломают запрос ---------- */

    public function test_send_succeeds_even_if_broadcast_transport_fails(): void
    {
        // имитируем недоступность realtime-канала: слушатель бросает исключение
        Event::listen(\App\Domains\Chat\Events\ChatDelta::class, function (): void {
            throw new \RuntimeException('reverb down');
        });

        $this->postJson('/chat/'.$this->direct->id.'/messages', ['body' => 'всё равно сохранено'])
            ->assertStatus(201);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->direct->id,
            'body' => 'всё равно сохранено',
        ]);
    }
}
