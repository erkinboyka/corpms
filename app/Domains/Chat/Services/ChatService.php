<?php

declare(strict_types=1);

namespace App\Domains\Chat\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Chat\Events\ChatDelta;
use App\Domains\Chat\Events\EmployeeChatNotice;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageEvent;
use App\Models\MessageReaction;
use App\Shared\Enums\ChatEventType;
use App\Shared\Enums\ConversationRole;
use App\Shared\Enums\ConversationType;
use App\Shared\Enums\ConversationVisibility;
use App\Shared\Enums\EmployeeStatus;
use App\Shared\Enums\MessageKind;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Ядро BM Chat.
 *
 * Принципы:
 *  - источник истины — база: сообщение сначала сохраняется, потом рассылается;
 *  - права проверяются на каждый диалог, а не только на вход в раздел;
 *  - межтенантная изоляция — company_id обязателен у диалога, а
 *    доступ к чужой компании невозможен в принципе;
 *  - повторная отправка того же client_id не создаёт вторую копию.
 */
class ChatService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
    ) {
    }

    /* ================================================================
       Доступ
       ================================================================ */

    /**
     * Право видеть диалог. Любое сомнение — отказ (fail-closed).
     */
    public function assertCanView(Employee $me, Conversation $conversation): void
    {
        TenantGuard::ensureCompany($conversation->company_id, $me);

        if ($conversation->is_archived && ! $this->isMember($me, $conversation)) {
            abort(404);
        }

        if ($this->isMember($me, $conversation)) {
            return;
        }

        // Канал компании виден всем сотрудникам компании без участия.
        if ($conversation->isCompanyWide()) {
            return;
        }

        abort(404);
    }

    /**
     * Право отправлять сообщения: членство + право раздела +
     * настройка канала (все / только администраторы).
     */
    public function assertCanPost(Employee $me, Conversation $conversation): void
    {
        $this->assertCanView($me, $conversation);

        if (! $this->permissions->check($me->user, 'chat.send')) {
            abort(403, __('Недостаточно прав на отправку сообщений'));
        }

        $member = $this->memberRow($me, $conversation);

        // Личная переписка и закрытая группа: писать может только участник.
        if (! $conversation->isCompanyWide()) {
            if ($member === null || ! $member->isActive()) {
                abort(403);
            }

            return;
        }

        // Канал компании: участники-администраторы пишут всегда,
        // остальные — если канал разрешает писать всем.
        if ($conversation->post_permission === 'admins') {
            if ($member === null || ! $member->isActive() || ! $member->isAdmin()) {
                abort(403, __('В этом канале писать могут только администраторы'));
            }
        }
    }

    /** Право управлять составом и настройками групп/каналов. */
    public function assertCanManage(Employee $me, Conversation $conversation): void
    {
        $this->assertCanView($me, $conversation);
        abort_unless($this->canManage($me, $conversation), 403);
    }

    public function canManage(Employee $me, Conversation $conversation): bool
    {
        if ($conversation->isDirect() || $conversation->company_id !== $me->company_id) {
            return false;
        }

        $member = $this->memberRow($me, $conversation);
        return $member !== null && $member->isActive()
            && ($member->role === ConversationRole::Owner
                || ($member->isAdmin() && $this->permissions->check($me->user, 'chat.manage')));
    }

    public function isMember(Employee $me, Conversation $conversation): bool
    {
        return $this->memberRow($me, $conversation)?->isActive() ?? false;
    }

    public function memberRow(Employee $me, Conversation $conversation): ?ConversationMember
    {
        return ConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('employee_id', $me->id)
            ->first();
    }

    /**
     * Best-effort realtime-сигнал ПОСЛЕ коммита в базу.
     *
     * БД — источник истины: если Reverb недоступен, сообщение уже
     * сохранено, и клиент догонит через polling дельт. Ошибка транспорта
     * никогда не должна ломать пользовательский запрос.
     */
    private function announce(Conversation $conversation, string $type, ?Message $message = null, ?Employee $actor = null): void
    {
        try {
            event(new ChatDelta($conversation, $type, $message, [
                'actor_employee_id' => $actor?->id,
            ]));
        } catch (\Throwable) {
            // realtime-канал недоступен — polling всё равно догонит
        }
    }

    /**
     * Личное уведомление участникам direct/group: обновить бейдж
     * непрочитанных. Каналы не оповещаем по одному — там бейдж
     * обновляется сводкой /chat/unread.
     */
    private function noticeMembers(Conversation $conversation, string $type, array $payload = [], ?Employee $except = null): void
    {
        if ($conversation->isChannel()) {
            return;
        }

        try {
            $members = ConversationMember::query()
                ->where('conversation_id', $conversation->id)
                ->whereNull('left_at')
                ->where('is_subscribed', true)
                ->when($except !== null, fn (Builder $q) => $q->where('employee_id', '!=', $except->id))
                ->with('employee')
                ->get();

            foreach ($members as $member) {
                if ($member->employee === null) {
                    continue;
                }

                event(new EmployeeChatNotice($member->employee, $type, $payload));
            }
        } catch (\Throwable) {
            // realtime-канал недоступен — polling всё равно догонит
        }
    }

    /* ================================================================
       Диалоги
       ================================================================ */

    /**
     * Список диалогов сотрудника: личные, группы и подписанные каналы,
     * отсортированные по последнему сообщению, с числом непрочитанных.
     *
     * @return Collection<int, Conversation>
     */
    public function listFor(Employee $me): Collection
    {
        $memberships = ConversationMember::query()
            ->where('employee_id', $me->id)
            ->whereNull('left_at')
            ->where('is_subscribed', true)
            ->with('conversation')
            ->get()
            ->filter(fn (ConversationMember $m): bool => $m->conversation !== null);

        $conversations = $memberships
            ->map(fn (ConversationMember $m): Conversation => $m->conversation)
            ->values();

        // Каналы компании видны всем сотрудникам, даже без подписки.
        $companyChannels = Conversation::query()
            ->where('company_id', $me->company_id)
            ->where('type', ConversationType::Channel->value)
            ->where('visibility', ConversationVisibility::Company->value)
            ->where('is_archived', false)
            ->get();

        $merged = $conversations
            ->concat($companyChannels)
            ->keyBy('id')
            ->values()
            ->sortByDesc(fn (Conversation $c) => $c->last_message_at?->getTimestamp() ?? 0)
            ->values();

        return $merged->map(function (Conversation $c) use ($me): Conversation {
            $c->setAttribute('unread_count', $this->unreadCount($me, $c));
            $c->setAttribute('my_member_role', $this->memberRow($me, $c)?->role?->value);

            return $c;
        });
    }

    public function unreadCount(Employee $me, Conversation $conversation): int
    {
        $member = $this->memberRow($me, $conversation);

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->where('employee_id', '!=', $me->id);

        if ($member !== null && $member->last_read_message_id !== null) {
            // whereKeyGreaterThan в этой версии фреймворка нет —
            // неизвестный метод ушёл бы в __call как колонка key_greater_than.
            $query->where('id', '>', $member->last_read_message_id);
        }

        return (int) $query->count();
    }

    /**
     * Личная переписка: создаёт диалог при первом обращении.
     * Обязательная проверка — оба сотрудника одной компании.
     */
    public function findOrCreateDirect(Employee $me, Employee $peer): Conversation
    {
        if ($peer->id === $me->id) {
            throw ValidationException::withMessages([
                'employee_id' => __('Нельзя создать диалог с самим собой'),
            ]);
        }

        TenantGuard::ensureCompany($peer->company_id, $me);

        if ($peer->trashed() || $peer->status !== \App\Shared\Enums\EmployeeStatus::Active) {
            throw ValidationException::withMessages([
                'employee_id' => __('Сотрудник недоступен'),
            ]);
        }

        $existing = Conversation::query()
            ->where('company_id', $me->company_id)
            ->where('type', ConversationType::Direct->value)
            ->whereHas('members', fn (Builder $q) => $q->where('employee_id', $me->id))
            ->whereHas('members', fn (Builder $q) => $q->where('employee_id', $peer->id))
            ->first();

        if ($existing !== null) {
            $this->memberRow($me, $existing)?->update(['is_subscribed' => true]);
            return $existing;
        }

        return DB::transaction(function () use ($me, $peer): Conversation {
            $conversation = Conversation::create([
                'company_id' => $me->company_id,
                'type' => ConversationType::Direct,
                'visibility' => ConversationVisibility::Private,
                'created_by' => $me->id,
            ]);

            foreach ([$me->id, $peer->id] as $employeeId) {
                ConversationMember::create([
                    'conversation_id' => $conversation->id,
                    'employee_id' => $employeeId,
                    'role' => ConversationRole::Member,
                    'joined_at' => now(),
                ]);
            }

            return $conversation;
        });
    }

    /** @param array<int> $employeeIds */
    public function createGroup(Employee $me, string $title, array $employeeIds, ?string $description = null): Conversation
    {
        if (! $this->permissions->check($me->user, 'chat.groups.create')) {
            abort(403, __('Недостаточно прав на создание групп'));
        }

        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $employeeIds[] = $me->id;

        $members = Employee::query()
            ->where('company_id', $me->company_id)
            ->whereIn('id', $employeeIds)
            ->whereNull('deleted_at')
            ->where('status', \App\Shared\Enums\EmployeeStatus::Active)
            ->pluck('id')
            ->all();

        if (count($members) < 2) {
            throw ValidationException::withMessages([
                'members' => __('Добавьте хотя бы одного другого сотрудника'),
            ]);
        }

        return DB::transaction(function () use ($me, $title, $members, $description): Conversation {
            $conversation = Conversation::create([
                'company_id' => $me->company_id,
                'type' => ConversationType::Group,
                'visibility' => ConversationVisibility::Private,
                'title' => $title,
                'description' => $description,
                'created_by' => $me->id,
            ]);

            foreach ($members as $employeeId) {
                ConversationMember::create([
                    'conversation_id' => $conversation->id,
                    'employee_id' => $employeeId,
                    'role' => $employeeId === $me->id ? ConversationRole::Owner : ConversationRole::Member,
                    'joined_at' => now(),
                ]);
            }

            $this->recordEvent($conversation, ChatEventType::MemberJoined, $me, null, [
                'members' => $members,
            ]);
            $this->audit->log($me->user, 'chat.group.created', 'Conversation', $conversation->id, null, [
                'title' => $title,
                'members' => count($members),
            ]);

            return $conversation;
        });
    }

    /**
     * @param array<int> $employeeIds
     * @param array<string, mixed> $data
     */
    public function createChannel(Employee $me, array $data): Conversation
    {
        if (! $this->permissions->check($me->user, 'chat.channels.create')) {
            abort(403, __('Недостаточно прав на создание каналов'));
        }

        $visibility = ConversationVisibility::from($data['visibility'] ?? 'company');

        $conversation = DB::transaction(function () use ($me, $data, $visibility): Conversation {
            $conversation = Conversation::create([
                'company_id' => $me->company_id,
                'type' => ConversationType::Channel,
                'visibility' => $visibility,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'post_permission' => $data['post_permission'] ?? 'all',
                'created_by' => $me->id,
            ]);

            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'employee_id' => $me->id,
                'role' => ConversationRole::Owner,
                'joined_at' => now(),
            ]);

            // Закрытый канал: сразу добавляем приглашённых.
            if ($visibility === ConversationVisibility::Private) {
                foreach ($data['member_ids'] ?? [] as $employeeId) {
                    $employeeId = (int) $employeeId;
                    if ($employeeId === $me->id) {
                        continue;
                    }

                    Employee::query()
                        ->whereKey($employeeId)
                        ->where('company_id', $me->company_id)
                        ->whereNull('deleted_at')
                        ->first()
                        ?->tap(fn () => ConversationMember::create([
                            'conversation_id' => $conversation->id,
                            'employee_id' => $employeeId,
                            'role' => ConversationRole::Member,
                            'joined_at' => now(),
                        ]));
                }
            }

            $this->audit->log($me->user, 'chat.channel.created', 'Conversation', $conversation->id, null, [
                'title' => $data['title'],
                'visibility' => $visibility->value,
            ]);

            return $conversation;
        });

        return $conversation;
    }

    public function updateConversation(Employee $me, Conversation $conversation, array $changes, ?UploadedFile $avatar, bool $resetAvatar): void
    {
        $this->assertCanManage($me, $conversation);
        $old = $conversation->avatar_path;
        $path = $this->storeImage($avatar, $conversation, 'avatars');
        $conversation->update([
            'title' => $changes['title'],
            'description' => $changes['description'] ?? null,
            'avatar_path' => $path ?? ($resetAvatar ? null : $old),
        ]);
        $this->deleteOldImage($old, $conversation->avatar_path, $conversation, 'avatars');
        $this->audit->log($me->user, 'chat.conversation.updated', 'Conversation', $conversation->id);
    }

    public function deleteConversation(Employee $me, Conversation $conversation): void
    {
        $this->assertCanView($me, $conversation);
        if ($conversation->isDirect()) {
            $this->memberRow($me, $conversation)->update(['is_subscribed' => false]);

            return;
        }

        $this->assertCanManage($me, $conversation);
        abort_unless($this->memberRow($me, $conversation)?->role === ConversationRole::Owner, 403);
        $conversation->delete();
        $this->audit->log($me->user, 'chat.conversation.deleted', 'Conversation', $conversation->id);
    }

    /** @param list<int> $employeeIds */
    public function addMembers(Employee $me, Conversation $conversation, array $employeeIds): void
    {
        $this->assertCanManage($me, $conversation);
        abort_if($conversation->isCompanyWide(), 403);
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        $eligible = Employee::query()->where('company_id', $me->company_id)
            ->where('status', EmployeeStatus::Active)->whereIn('id', $ids)->pluck('id');
        if ($eligible->count() !== count($ids)) {
            throw ValidationException::withMessages(['member_ids' => __('Некоторые сотрудники недоступны для добавления.')]);
        }

        DB::transaction(function () use ($me, $conversation, $eligible): void {
            $added = [];
            foreach ($eligible as $id) {
                $member = $conversation->members()->firstOrNew(['employee_id' => $id]);
                if ($member->exists && $member->isActive()) {
                    continue;
                }
                $member->fill(['role' => ConversationRole::Member, 'joined_at' => now(), 'left_at' => null, 'is_subscribed' => true])->save();
                $added[] = $id;
            }
            if ($added !== []) {
                $this->recordEvent($conversation, ChatEventType::MemberJoined, $me, null, ['members' => $added]);
                $this->audit->log($me->user, 'chat.members.added', 'Conversation', $conversation->id, null, ['members' => $added]);
            }
        });
    }

    public function removeMember(Employee $me, Conversation $conversation, int $employeeId): void
    {
        $this->assertCanManage($me, $conversation);
        abort_if($conversation->isCompanyWide() || $me->id === $employeeId, 403);
        $member = $conversation->members()->where('employee_id', $employeeId)->whereNull('left_at')->firstOrFail();
        abort_if($member->role === ConversationRole::Owner
            || ($member->isAdmin() && $this->memberRow($me, $conversation)?->role !== ConversationRole::Owner), 403);

        DB::transaction(function () use ($me, $conversation, $member, $employeeId): void {
            $member->update(['left_at' => now(), 'is_subscribed' => false]);
            $this->recordEvent($conversation, ChatEventType::MemberLeft, $me, null, ['employee_id' => $employeeId]);
            $this->audit->log($me->user, 'chat.member.removed', 'Conversation', $conversation->id, null, ['employee_id' => $employeeId]);
        });
    }

    public function setWallpaper(Employee $me, Conversation $conversation, ?UploadedFile $image): void
    {
        $this->assertCanView($me, $conversation);
        $member = $this->memberRow($me, $conversation);
        if ($member === null && $image === null) {
            return;
        }
        $old = $member?->wallpaper_path;
        $path = $this->storeImage($image, $conversation, 'wallpapers');
        $member ??= $conversation->members()->firstOrCreate(['employee_id' => $me->id], [
            'role' => ConversationRole::Member, 'joined_at' => now(), 'is_subscribed' => false,
        ]);
        $member->update(['wallpaper_path' => $path]);
        $this->deleteOldImage($old, $path, $conversation, 'wallpapers');
    }

    private function storeImage(?UploadedFile $image, Conversation $conversation, string $folder): ?string
    {
        if ($image === null) {
            return null;
        }
        $path = $image->store("chat/{$conversation->id}/{$folder}", 'local');
        abort_unless($path !== false, 500, __('Не удалось сохранить изображение.'));

        return $path;
    }

    private function deleteOldImage(?string $old, ?string $new, Conversation $conversation, string $folder): void
    {
        if ($old !== null && $old !== $new && str_starts_with($old, "chat/{$conversation->id}/{$folder}/")) {
            Storage::disk('local')->delete($old);
        }
    }

    /* ================================================================
       Сообщения
       ================================================================ */

    /**
     * История сообщений в хронологическом порядке (старые сверху, новые
     * внизу): лента чата растёт вниз, JS добавляет новые пузыри в конец.
     *
     * Берём последние N записей (orderByDesc + limit), при beforeId —
     * предыдущую страницу, затем разворачиваем в хронологию.
     *
     * @return Collection<int, Message>
     */
    public function messages(Employee $me, Conversation $conversation, ?int $beforeId = null, int $limit = 50): Collection
    {
        $this->assertCanView($me, $conversation);

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with(['author', 'attachments', 'reactions', 'replyTo.author'])
            ->orderByDesc('id')
            ->limit(min(max($limit, 1), 100));

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        return $query->get()->sortBy('id')->values();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(Employee $me, Conversation $conversation, array $data): Message
    {
        $this->assertCanPost($me, $conversation);

        $body = trim((string) ($data['body'] ?? ''));
        $clientId = isset($data['client_id']) ? substr((string) $data['client_id'], 0, 64) : null;

        if ($body === '' && empty($data['attachments'])) {
            throw ValidationException::withMessages(['body' => __('Сообщение пустое')]);
        }

        if (mb_strlen($body) > 8000) {
            throw ValidationException::withMessages(['body' => __('Сообщение слишком длинное')]);
        }

        // Идемпотентность: повтор того же client_id возвращает уже
        // сохранённое сообщение, а не создаёт дубликат.
        if ($clientId !== null) {
            $existing = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_id', $clientId)
                ->first();

            if ($existing !== null) {
                return $existing->load(['author', 'attachments', 'reactions', 'replyTo.author']);
            }
        }

        $replyTo = null;
        if (! empty($data['reply_to_id'])) {
            $replyTo = Message::query()
                ->whereKey((int) $data['reply_to_id'])
                ->where('conversation_id', $conversation->id)
                ->first();

            if ($replyTo === null) {
                throw ValidationException::withMessages(['reply_to_id' => __('Сообщение для ответа не найдено')]);
            }
        }

        $message = DB::transaction(function () use ($me, $conversation, $body, $clientId, $replyTo, $data): Message {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'employee_id' => $me->id,
                'kind' => MessageKind::Text,
                'body' => $body !== '' ? $body : null,
                'reply_to_id' => $replyTo?->id,
                'client_id' => $clientId,
            ]);

            $conversation->forceFill(['last_message_at' => now()])->saveQuietly();
            if ($conversation->isDirect()) {
                $conversation->members()->where('is_subscribed', false)->update(['is_subscribed' => true]);
            }

            $this->recordEvent($conversation, ChatEventType::Created, $me, $message);

            return $message;
        });

        $this->audit->log($me->user, 'chat.message.sent', 'Message', $message->id, null, [
            'conversation_id' => $conversation->id,
        ]);

        $this->announce($conversation, ChatEventType::Created->value, $message, $me);
        $this->noticeMembers($conversation, 'unread', ['conversation_id' => $conversation->id], $me);

        return $message->load(['author', 'attachments', 'reactions', 'replyTo.author']);
    }

    /** Редактирование доступно автору и только текстовых сообщений. */
    public function edit(Employee $me, Message $message, string $body): Message
    {
        $conversation = $message->conversation;
        abort_unless($conversation, 404);
        $this->assertCanView($me, $conversation);

        if ($message->employee_id !== $me->id) {
            abort(403, __('Редактировать можно только свои сообщения'));
        }

        if ($message->kind === MessageKind::System) {
            abort(403);
        }

        if ($message->isDeleted()) {
            throw ValidationException::withMessages(['body' => __('Сообщение удалено')]);
        }

        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('Сообщение пустое')]);
        }

        if (mb_strlen($body) > 8000) {
            throw ValidationException::withMessages(['body' => __('Сообщение слишком длинное')]);
        }

        $old = $message->body;
        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();

        $this->recordEvent($conversation, ChatEventType::Updated, $me, $message, [
            'edited' => true,
        ]);
        $this->audit->log($me->user, 'chat.message.edited', 'Message', $message->id, $old, $body);
        $this->announce($conversation, ChatEventType::Updated->value, $message, $me);

        return $message->load(['author', 'attachments', 'reactions', 'replyTo.author']);
    }

    /**
     * Удаление: автор — всегда; администратор диалога — по праву chat.manage.
     * Сообщение не исчезает бесследно: тело скрывается, служебная
     * запись остаётся, чтобы нить ответов не рвалась.
     */
    public function delete(Employee $me, Message $message): void
    {
        $conversation = $message->conversation;
        abort_unless($conversation, 404);
        $this->assertCanView($me, $conversation);

        $member = $this->memberRow($me, $conversation);
        $isManager = $member !== null && $member->isAdmin();

        if ($message->employee_id !== $me->id && ! $isManager) {
            abort(403, __('Удалять можно только свои сообщения'));
        }

        if ($message->kind === MessageKind::System) {
            abort(403);
        }

        $old = $message->body;
        $message->forceFill([
            'body' => null,
            'edited_at' => null,
            'pinned_at' => null,
            'pinned_by' => null,
        ])->delete();

        $this->recordEvent($conversation, ChatEventType::Deleted, $me, $message, [
            'by' => $me->id,
        ]);
        $this->audit->log($me->user, 'chat.message.deleted', 'Message', $message->id, $old, null);
        $this->announce($conversation, ChatEventType::Deleted->value, $message, $me);
    }

    /** Поставить или снять реакцию. */
    public function react(Employee $me, Message $message, string $emoji, bool $on): ?MessageReaction
    {
        $conversation = $message->conversation;
        abort_unless($conversation, 404);
        $this->assertCanView($me, $conversation);

        $emoji = mb_substr(trim($emoji), 0, 16);
        if ($emoji === '') {
            throw ValidationException::withMessages(['emoji' => __('Укажите реакцию')]);
        }

        if ($message->isDeleted()) {
            throw ValidationException::withMessages(['message' => __('Сообщение удалено')]);
        }

        // announce() должен выполняться после коммита транзакции, но до
        // return — иначе сигнал realtime никогда не уходит (баг: код стоял
        // после return и был недостижим).
        $reaction = DB::transaction(function () use ($me, $message, $emoji, $on, $conversation): ?MessageReaction {
            $query = MessageReaction::query()
                ->where('message_id', $message->id)
                ->where('employee_id', $me->id)
                ->where('emoji', $emoji);

            if ($on) {
                $reaction = MessageReaction::firstOrCreate([
                    'message_id' => $message->id,
                    'employee_id' => $me->id,
                    'emoji' => $emoji,
                ], ['created_at' => now()]);
            } else {
                $query->delete();
                $reaction = null;
            }

            $this->recordEvent($conversation, ChatEventType::Reaction, $me, $message, [
                'emoji' => $emoji,
                'on' => $on,
            ]);

            return $reaction;
        });

        $this->announce($conversation, ChatEventType::Reaction->value, $message, $me);

        return $reaction;
    }

    /**
     * Отметить диалог прочитанным до сообщения. Граница двигается только
     * вперёд — устаревший запрос не «недочитывает» ленту.
     */
    public function markRead(Employee $me, Conversation $conversation, ?int $upToMessageId = null): int
    {
        $this->assertCanView($me, $conversation);

        $member = $this->memberRow($me, $conversation);

        if ($member === null) {
            // Канал компании: участия нет, но прочитанное нужно считать.
            $member = ConversationMember::create([
                'conversation_id' => $conversation->id,
                'employee_id' => $me->id,
                'role' => ConversationRole::Member,
                'joined_at' => now(),
                'is_subscribed' => false,
            ]);
        }

        $targetId = $upToMessageId ?? (int) Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('deleted_at')
            ->max('id');

        $current = (int) $member->last_read_message_id;

        if ($targetId > $current) {
            $member->forceFill(['last_read_message_id' => $targetId])->saveQuietly();

            $this->recordEvent($conversation, ChatEventType::Read, $me, null, [
                'up_to' => $targetId,
            ]);

            // Другие вкладки того же сотрудника сразу сбрасывают бейдж.
            $this->announce($conversation, ChatEventType::Read->value, null, $me);
        }

        return $this->unreadCount($me, $conversation);
    }

    public function toggleSubscription(Employee $me, Conversation $conversation, bool $subscribe): ConversationMember
    {
        $this->assertCanView($me, $conversation);

        if (! $conversation->isChannel() && ! $conversation->isCompanyWide()) {
            abort(403, __('Подписка доступна только для каналов'));
        }

        $member = $this->memberRow($me, $conversation)
            ?? ConversationMember::create([
                'conversation_id' => $conversation->id,
                'employee_id' => $me->id,
                'role' => ConversationRole::Member,
                'joined_at' => now(),
            ]);

        $member->forceFill(['is_subscribed' => $subscribe])->save();

        return $member;
    }

    /* ================================================================
       Синхронизация (дельты после потери соединения)
       ================================================================ */

    /**
     * События диалога после указанного id — клиент догоняет состояние
     * без полной перезагрузки ленты.
     *
     * @return Collection<int, MessageEvent>
     */
    public function eventsSince(Employee $me, Conversation $conversation, int $sinceId, int $limit = 100): Collection
    {
        $this->assertCanView($me, $conversation);

        return MessageEvent::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $sinceId)
            ->with(['message.attachments', 'message.reactions', 'message.author', 'message.replyTo.author'])
            ->orderBy('id')
            ->limit(min(max($limit, 1), 300))
            ->get();
    }

    /** @internal используется из сервисов и слушателей */
    public function recordEvent(
        Conversation $conversation,
        ChatEventType $type,
        Employee $actor,
        ?Message $message = null,
        ?array $payload = null,
    ): MessageEvent {
        return MessageEvent::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message?->id,
            'type' => $type,
            'actor_employee_id' => $actor->id,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
