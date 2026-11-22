<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** P1 SEC-05: per-account TOTP limiter, аудит неудач и уведомление владельцу. */
class TwoFactorSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('2fa.challenge:1');
        RateLimiter::clear('2fa.confirm:1');
    }

    public function test_challenge_is_locked_after_five_bad_codes_from_different_ips(): void
    {
        [$user, $employee] = $this->makeTwoFactorUser();

        $this->withSession(['2fa:user_id' => $user->id]);

        for ($i = 0; $i < 4; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.'.($i + 1)])
                ->post('/login/2fa', ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.99'])
            ->post('/login/2fa', ['code' => '000000'])
            ->assertStatus(429);

        $this->assertGuest();
        $this->assertSame(5, AuditLog::query()->where('action', '2fa.failed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', '2fa.locked')->count());
        $this->assertSame(1, AppNotification::query()
            ->where('recipient_id', $employee->id)
            ->where('type', 'security.2fa_locked')
            ->count());
    }

    /** @return array{0: User, 1: Employee} */
    private function makeTwoFactorUser(): array
    {
        $company = Company::create(['name' => '2FA Co', 'code' => 'twofaco']);
        $user = User::factory()->create();
        $secret = app(TwoFactorService::class)->generateSecret();
        $user->forceFill([
            'two_factor_secret' => app(TwoFactorService::class)->encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'employee_no' => '2FA-1',
            'first_name' => 'Двухфакторный',
            'last_name' => 'Пользователь',
            'status' => 'active',
        ]);

        RateLimiter::clear('2fa.challenge:'.$user->id);
        RateLimiter::clear('2fa.confirm:'.$user->id);

        return [$user, $employee];
    }
}
