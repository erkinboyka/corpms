<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Восстановление пароля (P1-2 аудита): запрос ссылки на email → сброс по токену.
 *
 * Защита: throttle на маршрутах (IP) + внутренний throttle broker'а (по email),
 * ответ одинаков для существующего и несуществующего адреса (нет user enumeration),
 * политика нового пароля — минимум 12 символов, вход и регенерация сессии после сброса.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function forgotForm(): View
    {
        return view('auth.forgot-password');
    }

    /** Отправка ссылки для сброса: статус не раскрывает, существует ли учётка. */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [], ['email' => 'Email']);

        $status = Password::broker()->sendResetLink($request->only('email'));

        $message = match ($status) {
            Password::RESET_LINK_SENT => 'Если учётная запись существует, ссылка для сброса пароля отправлена на почту.',
            // USER_NOT_FOUND и THROTTLED — одинаковая нейтральная фраза
            default => 'Если учётная запись существует, ссылка для сброса пароля отправлена на почту. '
                .'При повторной отправке подождите минуту.',
        };

        return back()->with('status', $message);
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /** Сброс по токену: старые сессии не трогаем, но после сброса — вход и regenerate. */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(12), 'confirmed'],
        ], [
            'password.min' => 'Новый пароль должен содержать не менее 12 символов.',
        ], [
            'email' => 'Email',
            'password' => 'Пароль',
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password, // cast hashed
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Ссылка для сброса неверна или устарела. Запросите новую.',
            ]);
        }

        $user = User::query()->where('email', $request->input('email'))->first();
        $this->audit->log($user, 'auth.password_reset_self', 'user', $user?->id);

        if ($user !== null) {
            auth()->login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('dashboard')->with('status', 'Пароль изменён. Выполнен вход в систему.');
    }
}
