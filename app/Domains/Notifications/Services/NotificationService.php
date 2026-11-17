<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Models\AppNotification;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Центральная шина уведомлений (п.13, 71 ТЗ):
 *  - in-app: attendance_notifications читаются в UI (колокольчик);
 *  - telegram: http-запрос в Bot API, если канал выбран и у сотрудника есть chat_id.
 */
class NotificationService
{
    public const CHANNEL_APP = 'app';

    public const CHANNEL_TELEGRAM = 'telegram';

    public const CHANNEL_EMAIL = 'email';

    public function notify(
        Employee $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?object $subject = null,
        string $channel = self::CHANNEL_APP,
    ): AppNotification {
        $notification = AppNotification::create([
            'recipient_id' => $recipient->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'channel' => $channel,
        ]);

        if ($channel === self::CHANNEL_TELEGRAM) {
            $this->sendTelegram($recipient, $title, $body);
        }

        return $notification;
    }

    /** Массовая рассылка в Telegram (шедуляр/события). */
    public function broadcastTelegram(iterable $employees, string $title, ?string $body = null): int
    {
        $sent = 0;

        foreach ($employees as $employee) {
            try {
                if ($this->sendTelegram($employee, $title, $body)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                Log::warning('telegram broadcast failed', ['employee_id' => $employee->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** Telegram Bot API: sendMessage. Сотрудники без chat_id пропускаются. */
    public function sendTelegram(Employee $employee, string $title, ?string $body = null): bool
    {
        $chatId = $employee->telegram_chat_id;

        if ($chatId === null || $chatId === '') {
            return false;
        }

        $token = config('corpms.telegram.bot_token');

        if ($token === null || $token === '') {
            // не сконфигурировано: отложено (не роняем основной поток)
            return false;
        }

        $response = Http::timeout(5)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $body === null ? $title : $title."\n\n".$body,
                'parse_mode' => 'HTML',
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::warning('telegram sendMessage failed', [
            'employee_id' => $employee->id,
            'status' => $response->status(),
        ]);

        return false;
    }

    /** Пометить прочитанным (проверка владельца — на уровне контроллера). */
    public function markRead(AppNotification $notification): void
    {
        $notification->forceFill(['read_at' => now()])->saveQuietly();
    }

    public function unreadCount(Employee $recipient): int
    {
        return AppNotification::query()
            ->where('recipient_id', $recipient->id)
            ->whereNull('read_at')
            ->count();
    }

    public function recentFor(Employee $recipient, int $limit = 20): Collection
    {
        return AppNotification::query()
            ->where('recipient_id', $recipient->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
