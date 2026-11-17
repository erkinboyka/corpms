<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domains\Attendance\Services\AttendanceService;
use App\Domains\Biometric\Services\BiometricService;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Notifications\Services\TelegramService;
use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ТЗ п.13, 66: уведомления in-app + Telegram (каналы, привязка бота, webhook).
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected NotificationService $notifications;
    protected TelegramService $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Co', 'code' => 'testco']);
        $this->notifications = app(NotificationService::class);
        $this->telegram = app(TelegramService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
    }

    /* ---------------- in-app уведомления ---------------- */

    public function test_inapp_notification_and_unread_count(): void
    {
        $employee = $this->makeEmployee('N-1', 'Иван', 'Иванов');

        $this->notifications->notify($employee, 'attendance.late', 'Опоздание', 'На 15 мин');
        $this->notifications->notify($employee, 'test', 'Второе');

        $this->assertEquals(2, $this->notifications->unreadCount($employee));

        $first = AppNotification::query()->where('recipient_id', $employee->id)->orderBy('id')->first();
        $this->notifications->markRead($first);

        $this->assertEquals(1, $this->notifications->unreadCount($employee));
    }

    public function test_telegram_channel_sends_message_when_configured(): void
    {
        config(['corpms.telegram.bot_token' => '12345:TGTEST', 'corpms.telegram.bot_username' => 'CorpMS_bot']);

        $employee = $this->makeEmployee('N-2', 'Мария', 'Отправка', chatId: '445566');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $notification = $this->notifications->notify(
            $employee,
            'test.tg',
            'Проверка канала',
            'Текст',
            channel: NotificationService::CHANNEL_TELEGRAM,
        );

        $this->assertNotNull($notification);
        $this->assertSame('telegram', $notification->channel);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '12345:TGTEST/sendMessage')
            && $request['chat_id'] === '445566'
            && str_contains($request['text'], 'Проверка канала'));
    }

    public function test_broadcast_skips_employees_without_chat_id(): void
    {
        config(['corpms.telegram.got_token' => '12345:TGTEST']);
        config(['corpms.telegram.bot_token' => '12345:TGTEST']);

        $withChat = $this->makeEmployee('N-3', 'Село', 'Сопл', chatId: '111');
        $withoutChat = $this->makeEmployee('N-4', 'Без', 'Чата');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $sent = $this->notifications->broadcastTelegram([$withChat, $withoutChat], 'Массироовка');

        $this->assertEquals(1, $sent);
    }

    /* ---------------- опоздание сотрудники/директор ---------------- */

    public function test_late_arrival_notifies_employee_and_director(): void
    {
        // триггер: событие биометрии → AttendanceRecorded → listener
        $schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'grace_minutes' => 10,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id,
            'name' => 'Продажи',
            'code' => 'SALES',
        ]);

        $director = $this->makeEmployee('DIRT-1', 'Ольга', 'Руководец');
        $department->update(['director_id' => $director->id]);

        $employee = $this->makeEmployee('SLR-1', 'Анна', 'Опоздавшая');
        $employee->update(['department_id' => $department->id, 'work_schedule_id' => $schedule->id]);
        $employee->refresh();

        // вешаем director: новый employee связан dept
        $device = BiometricDevice::create([
            'company_id' => $this->company->id,
            'name' => 'Камера WB1',
            'serial_number' => 'SN-9899',
            'direction' => 'in',
            'api_token_hash' => 'hash-in',
            'status' => 'online',
        ]);

        $event = \App\Models\BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'event_uuid' => 'late-ev-1',
            'occurred_at' => '2026-11-02 09:45:00',
            'direction' => 'in',
            'raw_payload' => [],
            'processing_status' => 'pending',
        ]);

        $service = app(AttendanceService::class);
        $record = $service->applyEvent($employee, $event);

        $event->forceFill(['processing_status' => 'processed'])->save();
        event(new \App\Domains\Attendance\Events\AttendanceRecorded($record, $event));

        $this->assertSame('late', $record->status->value);

        // сотрудник получил in-app оповещение
        $this->assertDatabaseHas('attendance_notifications', [
            'recipient_id' => $employee->id,
            'type' => 'attendance.late',
            'channel' => 'app',
        ]);

        // руководитель: telegram-уведомление от лисенера
        $this->assertDatabaseHas('attendance_notifications', [
            'recipient_id' => $director->id,
            'type' => 'attendance.late_director',
            'channel' => 'telegram',
        ]);
    }

    /* ---------------- Telegram бот ---------------- */

    public function test_link_code_flow_binds_chat(): void
    {
        $employee = $this->makeEmployee('TG-1', 'Павел', 'Связочник');

        $code = $this->telegram->issueLinkCode($employee);

        $this->assertNotNull($employee->fresh()->telegram_link_code);
        $this->assertStringContainsString('?start=' . $code, $this->telegram->deepLink($code));

        $reply = $this->telegram->resolveCommand('/start ' . $code, '777333');

        $this->assertStringContainsString('Привязка выполнена', $reply);
        $this->assertSame('777333', $employee->fresh()->telegram_chat_id);
        $this->assertNull($employee->fresh()->telegram_link_code);
    }

    public function test_expired_or_unknown_link_code_rejected(): void
    {
        $employee = $this->makeEmployee('TG-2', 'Ольга', 'Неуспешная');
        $code = $this->telegram->issueLinkCode($employee);

        Carbon::setTestNow(Carbon::parse('2026-11-02 11:00:00')); // +1ч — TTL истёк

        $reply = $this->telegram->resolveCommand('/start ' . $code, '555555');
        $this->assertStringContainsString('истёк', $reply);
        $this->assertNull($employee->fresh()->telegram_chat_id);

        $unknown = $this->telegram->resolveCommand('/start NOTCODE', '555555');
        $this->assertStringContainsString('Код не найден', $unknown);
    }

    public function test_status_command_for_bound_employee(): void
    {
        $employee = $this->makeEmployee('TG-3', 'Дмитрий', 'Отчитываемый', chatId: '900');

        $schedule = WorkSchedule::create([
            'company_id' => $this->company->id,
            'name' => 'std',
            'type' => 'standard',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'grace_minutes' => 10,
        ]);
        $employee->update(['work_schedule_id' => $schedule->id]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-11-02',
            'clock_in_at' => '2026-11-02 09:02:00',
            'clock_out_at' => null,
            'status' => 'on_time',
            'source' => 'biometric',
        ]);

        $reply = $this->telegram->resolveCommand('/status', '900');

        $this->assertStringContainsString('Табель за 02.11.2026', $reply);
        $this->assertStringContainsString('Вход: 09:02', $reply);
        $this->assertStringContainsString('Выход: —', $reply);
    }

    public function test_unbound_employee_gets_hint(): void
    {
        $reply = $this->telegram->resolveCommand('/status', 'UNKNOWN_CHAT');

        $this->assertStringContainsString('не привязан', $reply);
    }

    /* ---------------- webhook HTTP ---------------- */

    public function test_webhook_requires_secret(): void
    {
        config(['corpms.telegram.webhook_secret' => 'topsecret123']);
        config(['corpms.telegram.bot_token' => '999:test']);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/telegram/webhook/wrongsecret', ['message' => ['text' => '/help', 'chat' => ['id' => 1]]])
            ->assertStatus(403);

        $this->postJson('/api/telegram/webhook/topsecret123', ['message' => ['text' => '/help', 'chat' => ['id' => 1]]])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('handled', true);
    }

    /* ---------------- helpers ---------------- */

    protected function makeEmployee(string $no, string $first, string $last, ?string $chatId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'telegram_chat_id' => $chatId,
        ]);
    }
}
