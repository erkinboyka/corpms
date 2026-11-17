<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram-бот (п.66 ТЗ): привязка сотрудника к чату (/start <code>) +
 * базовые команды (/status, /help). Апдейты проходят через
 * TelegramWebhookController → handleUpdate.
 */
class TelegramService
{
    /** Метка кода привязки и время действия (минуты). */
    protected const LINK_CODE_TTL_MINUTES = 30;

    public function __construct(protected NotificationService $notifications)
    {
    }

    /* -------------------- привязка -------------------- */

    /** Генерация кода привязки для сотрудника (UI "Настройки → Telegram"). */
    public function issueLinkCode(Employee $employee): string
    {
        $code = strtoupper(Str::random(8));

        $employee->forceFill([
            'telegram_link_code' => $code,
            'telegram_link_expires_at' => now()->addMinutes(self::LINK_CODE_TTL_MINUTES),
        ])->save();

        return $code;
    }

    public function deepLink(string $code): string
    {
        $username = (string) config('corpms.telegram.bot_username');

        return "https://t.me/{$username}?start={$code}";
    }

    /** /start <code> → связать chat_id (п.66 ТЗ). */
    protected function handleStart(Employee $employee, string $chatId): string
    {
        $expiresAt = $employee->telegram_link_expires_at;

        if ($expiresAt !== null && now()->gt($expiresAt)) {
            return 'Код привязки истёк. Запросите новый в личном кабинете.';
        }

        // не занимать chat_id под двумя сотрудниками
        Employee::query()
            ->where('telegram_chat_id', $chatId)
            ->where('id', '!=', $employee->id)
            ->update(['telegram_chat_id' => null]);

        $employee->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_link_code' => null,
            'telegram_link_expires_at' => null,
        ])->save();

        $name = $employee->last_name . ' ' . $employee->first_name;

        return "Привязка выполнена, {$name}. Сейчас тут доступны: /status — сводка за сегодня, /help — справка.";
    }

    /* -------------------- обработка апдейта -------------------- */

    /** Обрабатывает один Telegram update, отправляет ответ в чат. Вернёт true при успехе. */
    public function handleUpdate(array $update): bool
    {
        $message = $update['message'] ?? null;

        if (!isset($message['text']) || !isset($message['chat']['id'])) {
            return false;
        }

        $chatId = (string) $message['chat']['id'];
        $text = trim((string) $message['text']);

        $reply = $this->resolveCommand($text, $chatId);

        return $this->sendMessage($chatId, $reply);
    }

    public function resolveCommand(string $text, string $chatId): string
    {
        // /start XYZ123 (плюс @botname суффикс: "/start@CorpMS_bot ABC")
        if (preg_match('#^/start(?:@\S+)?(?:\s+(\S+))?$#u', $text, $m) === 1) {
            if (!isset($m[1])) {
                return 'Отправьте /start <код привязки> (получите код в личном кабинете CorpMS).';
            }

            $employee = Employee::query()->where('telegram_link_code', mb_strtoupper($m[1]))->first();

            if ($employee === null) {
                return 'Код не найден. Проверьте и повторите.';
            }

            return $this->handleStart($employee, $chatId);
        }

        // команды доступные только привязанным сотрудникам
        $employee = Employee::query()->where('telegram_chat_id', $chatId)->first();

        if ($employee === null) {
            return 'Сотрудник не привязан. Отправьте /start <код> из личного кабинета CorpMS.';
        }

        return match (true) {
            preg_match('#^/status(?:@\S+)?$#u', $text) === 1 => $this->statusReply($employee),
            preg_match('#^/help(?:@\S+)?$#u', $text) === 1 => 'Доступные команды: /status — сводка за сегодня, /help — эта справка.',
            default => 'Неизвестная команда. /help — справка.',
        };
    }

    protected function statusReply(Employee $employee): string
    {
        $today = \App\Models\AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        $lines = [];

        $lines[] = 'Табель за ' . now()->format('d.m.Y') . ':';

        if ($today !== null) {
            $lines[] = 'Статус: ' . $today->status->value;
            $lines[] = 'Вход: ' . ($today->clock_in_at?->format('H:i') ?? '—');
            $lines[] = 'Выход: ' . ($today->clock_out_at?->format('H:i') ?? '—');
            $lines[] = 'Отработано: ' . $today->worked_minutes . ' мин.';
        } else {
            $lines[] = 'Записей нет.';
        }

        $openTasks = \App\Models\Task::query()
            ->whereIn('id', \App\Models\TaskAssignment::query()
                ->where('employee_id', $employee->id)
                ->select('task_id'))
            ->whereIn('status', ['new', 'in_progress', 'review'])
            ->count();

        $lines[] = 'Открытых задач: ' . $openTasks;

        $unread = $this->notifications->unreadCount($employee);
        $lines[] = 'Новых уведомлений: ' . $unread;

        return implode("\n", $lines);
    }

    /* -------------------- отправка -------------------- */

    public function sendMessage(string $chatId, string $text): bool
    {
        $token = (string) config('corpms.telegram.bot_token');

        if ($token === '') {
            return false;
        }

        try {
            $response = Http::timeout(5)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('telegram sendMessage failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
