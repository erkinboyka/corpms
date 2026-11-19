<?php

declare(strict_types=1);

namespace App\Domains\Chat\Events;

use App\Models\Employee;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Личное уведомление сотруднику: изменилось число непрочитанных,
 * его добавили в диалог. Канал персональный — видит только сам сотрудник,
 * поэтому payload ограничен идентификаторами, без тел сообщений.
 */
class EmployeeChatNotice implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly Employee $employee,
        public readonly string $type,
        public readonly array $payload = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return array_merge([
            'type' => $this->type,
            'employee_id' => $this->employee->id,
        ], $this->payload);
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('employee.'.$this->employee->id.'.chat'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.notice';
    }
}
