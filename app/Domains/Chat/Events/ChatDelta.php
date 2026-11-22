<?php

declare(strict_types=1);

namespace App\Domains\Chat\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Сигнал «в диалоге появилось новое событие».
 *
 * Канал получает только уведомление без тела сообщения: клиент сразу
 * запрашивает дельты у /events?since=N. Источник истины — база, поэтому
 * потерянный broadcast ничего не ломает — догонит polling, а потерянный
 * polling догонит broadcast. Так тело сообщения не проходит через
 * авторизацию канала дважды, а права проверяются один раз — в ChatService.
 *
 * ShouldBroadcastNow + подавление ошибок в ChatService: отправка
 * сообщения не должна падать, если realtime-сервер недоступен.
 */
class ChatDelta implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  идентификаторы для клиента
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $type,
        public readonly ?Message $message = null,
        public readonly array $payload = [],
    ) {}

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'type' => $this->type,
            'message_id' => $this->message?->id,
            'actor_employee_id' => $this->payload['actor_employee_id'] ?? null,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.'.$this->conversation->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.delta';
    }
}
