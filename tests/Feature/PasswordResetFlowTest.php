<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * S6 (спринт по аудиту): восстановление пароля + ужесточённая политика (12+).
 *
 * Проверяется: запрос ссылки без утечки существования учётки, валидный токен,
 * отказ по слабому паролю, вход после сброса, старый пароль перестаёт работать.
 */
class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected const OLD_PASSWORD = 'existing-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'reset@tenant.test',
            'password' => self::OLD_PASSWORD,
        ]);
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Забыли пароль?');
    }

    public function test_unknown_email_does_not_reveal_account_and_sends_nothing(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@tenant.test'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertNothingSent();

        // ответ не содержит упоминания «не найдено»
        $status = session('status');
        $this->assertStringNotContainsString('не найден', (string) $status);
    }

    public function test_full_reset_flow_updates_password_and_logs_in(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => $this->user->email])->assertRedirect();

        $token = null;
        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertNotNull($token);

        // форма сброса открывается по токену
        $this->get('/reset-password/'.$token.'?email='.$this->user->email)
            ->assertOk()
            ->assertSee('Новый пароль');

        // слабый пароль (10 символов) отклоняется политикой 12+
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'short-pass1',
            'password_confirmation' => 'short-pass1',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->fresh()->password));

        // валидный сброс: пароль обновлён, старый не работает, сессия активна
        $newPassword = 'brand-new-secret-42';
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('dashboard'));

        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check($newPassword, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
        $this->assertAuthenticatedAs($fresh);

        // повторное использование той же ссылки запрещено
        // (перед повторным POST выходим: контроллер после сброса авторизует
        //  пользователя, а маршрут находится в группе guest)
        $this->post('/logout');
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'another-secret-99',
            'password_confirmation' => 'another-secret-99',
        ])->assertSessionHasErrors('email');
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->post('/reset-password', [
            'token' => 'bogus-token',
            'email' => $this->user->email,
            'password' => 'brand-new-secret-42',
            'password_confirmation' => 'brand-new-secret-42',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->fresh()->password));
    }

    public function test_authenticated_users_cannot_open_forgot_password(): void
    {
        $this->actingAs($this->user)
            ->get('/forgot-password')
            ->assertRedirect(route('dashboard'));
    }

    public function test_change_password_policy_requires_twelve_characters(): void
    {
        // 8–11 символов теперь недостаточно
        $this->actingAs($this->user)->put('/profile/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'short-pass1',
            'password_confirmation' => 'short-pass1',
        ])->assertSessionHasErrors('password');

        $this->actingAs($this->user)->put('/profile/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertTrue(
            Hash::check('long-enough-password', $this->user->fresh()->password)
        );
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        // throttle:10,1 на маршруте — 11-й запрос подряд от одного IP получает 429
        for ($i = 0; $i < 10; $i++) {
            $this->post('/forgot-password', ['email' => 'reset@tenant.test'])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'reset@tenant.test'])->assertStatus(429);
    }
}
