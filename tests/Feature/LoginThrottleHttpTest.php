<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Фаза A, задача 5: rate limiting входа, лок аккаунта после неудач,
 * аудит попыток входа (ТЗ §11).
 */
class LoginThrottleHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ключи троттлинга чистые между тестами (кеш — array, но на всякий случай)
        RateLimiter::clear('login:victim@tenant.test');
    }

    private function attempt(string $email, string $password = 'wrong-password')
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    /* ---------------- лок аккаунта ---------------- */

    public function test_account_locked_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'victim@tenant.test', 'password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->attempt('victim@tenant.test')
                ->assertSessionHasErrors('email');
        }

        // даже ПРАВИЛЬНЫЙ пароль отклоняется до конца окна
        $this->attempt('victim@tenant.test', 'password')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'Слишком много неудачных попыток',
            (string) session('errors')->first('email')
        );
    }

    public function test_lockout_is_per_account_not_global(): void
    {
        User::factory()->create(['email' => 'victim@tenant.test', 'password' => 'password']);
        User::factory()->create(['email' => 'other@tenant.test', 'password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->attempt('victim@tenant.test');
        }

        // второй аккаунт при том же IP не заблокирован
        $this->attempt('other@tenant.test', 'password')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs(User::where('email', 'other@tenant.test')->first());
    }

    public function test_successful_login_clears_failure_counter(): void
    {
        User::factory()->create(['email' => 'victim@tenant.test', 'password' => 'password']);

        // 4 неудачи — лок ещё не наступил
        for ($i = 0; $i < 4; $i++) {
            $this->attempt('victim@tenant.test');
        }

        $this->attempt('victim@tenant.test', 'password')->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(0, RateLimiter::attempts('login:victim@tenant.test'), 'успех должен сбрасывать счётчик');

        // выходим и снова ошибаемся: счётчик начинается с нуля, лока нет
        $this->post('/logout')->assertRedirect(route('login'));
        $this->attempt('victim@tenant.test')->assertSessionHasErrors('email');
        $this->assertSame(1, RateLimiter::attempts('login:victim@tenant.test'));
    }

    public function test_unknown_email_also_counts_toward_lockout(): void
    {
        // перебор несуществующих ящиков не должен идти бесконечно
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('ghost@tenant.test')->assertSessionHasErrors('email');
        }

        $this->attempt('ghost@tenant.test')->assertSessionHasErrors('email');

        $this->attempt('ghost@tenant.test')->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Слишком много неудачных попыток',
            (string) session('errors')->first('email')
        );
    }

    /* ---------------- аудит ---------------- */

    public function test_failed_and_successful_logins_are_audited(): void
    {
        User::factory()->create(['email' => 'victim@tenant.test', 'password' => 'password']);

        $this->attempt('victim@tenant.test');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_failed',
        ]);

        $this->attempt('victim@tenant.test', 'password');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'user_id' => User::where('email', 'victim@tenant.test')->value('id'),
        ]);

        // в аудите не должен утекать пароль
        $failed = AuditLog::where('action', 'auth.login_failed')->sole();
        $this->assertStringNotContainsString('wrong-password', (string) json_encode($failed->new_value));
    }

    /* ---------------- IP-throttle ---------------- */

    public function test_ip_throttle_returns_429_after_limit(): void
    {
        // 30/мин — лимит маршрута; 31-я попытка с того же IP блокируется
        for ($i = 0; $i < 30; $i++) {
            $this->attempt("flood{$i}@tenant.test");
        }

        $this->attempt('flood31@tenant.test')->assertStatus(429);
    }
}
