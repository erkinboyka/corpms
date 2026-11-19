<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Неудачных попыток входа на один аккаунт. */
    private const FAIL_LIMIT = 5;

    /** Окно блокировки аккаунта, секунд (15 минут). */
    private const FAIL_DECAY = 900;

    public function __construct(private readonly AuditService $audit) {}

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => 'Email',
            'password' => 'Пароль',
        ]);

        // Брутфорс одного аккаунта: после FAIL_LIMIT неудач блокируем даже
        // правильный пароль до конца окна (ТЗ §11 «мониторинг подозрительной активности»).
        $key = $this->throttleKey($credentials['email']);

        if (RateLimiter::tooManyAttempts($key, self::FAIL_LIMIT)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw ValidationException::withMessages([
                'email' => "Слишком много неудачных попыток входа. Попробуйте через {$minutes} мин.",
            ]);
        }

        $remember = $request->boolean('remember');
        $credentials[] = fn ($query) => $query->where(fn ($scope) => $scope
            ->whereDoesntHave('employee')
            ->orWhereHas('employee', fn ($employee) => $employee->where('status', 'active')));

        if (Auth::attempt($credentials, $remember) === false) {
            RateLimiter::hit($key, self::FAIL_DECAY);

            // Аудит неудачного входа (user_id неизвестен или null)
            $this->audit->log(
                User::query()->where('email', $credentials['email'])->first(),
                'auth.login_failed',
                'user',
                null,
                null,
                ['email' => $credentials['email'], 'ip' => $request->ip()],
            );

            throw ValidationException::withMessages([
                'email' => 'Неверный email или пароль.',
            ]);
        }

        RateLimiter::clear($key);

        $user = $request->user();
        $this->audit->log($user, 'auth.login', 'user', $user?->id);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Ключ троттлинга по аккаунту (регистронезависимо). */
    private function throttleKey(string $email): string
    {
        return 'login:'.Str::lower(trim($email));
    }
}
