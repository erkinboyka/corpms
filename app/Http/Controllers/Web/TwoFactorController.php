<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Notifications\Services\NotificationService;
use App\Models\User;
use App\Support\QrCodeGenerator;
use App\Support\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorController
{
    /** SEC-05: лимит на аккаунт, а не на IP — IP легко сменить. */
    private const CHALLENGE_LIMIT = 5;

    private const CONFIRM_LIMIT = 5;

    private const DECAY_SECONDS = 600;

    public function __construct(
        private TwoFactorService $twoFactor,
        private AuditService $audit,
        private NotificationService $notifications,
    ) {}

    // Challenge (guest)
    public function showChallenge(Request $request)
    {
        if (! $request->session()->has('2fa:user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor');
    }

    public function verifyChallenge(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);
        $userId = $request->session()->get('2fa:user_id');
        if (! $userId) {
            return redirect()->route('login');
        }

        $user = User::find($userId);
        if (! $user || ! $user->two_factor_secret) {
            return redirect()->route('login');
        }

        $limitKey = '2fa.challenge:'.$user->id;
        $this->assertNotLocked($limitKey, self::CHALLENGE_LIMIT);

        $secret = $this->twoFactor->decryptSecret($user->two_factor_secret);
        if (! $this->twoFactor->verify($secret, (string) $request->input('code'))) {
            $this->registerFailure($request, $user, $limitKey, self::CHALLENGE_LIMIT, 'challenge');
        }

        RateLimiter::clear($limitKey);
        Auth::login($user, (bool) $request->session()->get('2fa:remember', false));
        $request->session()->forget(['2fa:user_id', '2fa:remember']);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    // Settings (auth)
    public function index(Request $request)
    {
        $user = $request->user();
        $secret = null;
        $qr = null;
        $qrSvg = null;
        if (! $user->two_factor_confirmed_at) {
            $secret = $this->twoFactor->generateSecret();
            $request->session()->put('2fa:pending_secret', $secret);
            $qr = $this->twoFactor->qrUrl($user->email, $secret);
            $qrSvg = QrCodeGenerator::svg($qr, 220, 'twofa-qr__svg');
        }

        return view('profile.two-factor', [
            'user' => $user,
            'secret' => $secret,
            'qr' => $qr,
            'qrSvg' => $qrSvg,
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        $limitKey = '2fa.confirm:'.$user->id;
        $this->assertNotLocked($limitKey, self::CONFIRM_LIMIT);

        $secret = $request->session()->get('2fa:pending_secret');
        if (! $secret) {
            return redirect()->route('profile.2fa');
        }

        if (! $this->twoFactor->verify((string) $secret, (string) $request->input('code'))) {
            $this->registerFailure($request, $user, $limitKey, self::CONFIRM_LIMIT, 'confirm');
        }

        RateLimiter::clear($limitKey);
        $user->forceFill([
            'two_factor_secret' => $this->twoFactor->encryptSecret((string) $secret),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->forget('2fa:pending_secret');

        return redirect()->route('profile.2fa')->with('status', __('2FA включена'));
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('Неверный пароль.')]);
        }

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('profile.2fa')->with('status', __('2FA отключена'));
    }

    /** SEC-05: блокировка по аккаунту проверяется до самой проверки кода. */
    private function assertNotLocked(string $key, int $limit): void
    {
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            abort(429, __('Слишком много неверных кодов 2FA. Попробуйте позже.'));
        }
    }

    /** SEC-05: аудит каждой неудачи + уведомление и блокировка по достижении порога. */
    private function registerFailure(
        Request $request,
        User $user,
        string $key,
        int $limit,
        string $context,
    ): void {
        $attempts = RateLimiter::hit($key, self::DECAY_SECONDS);

        $this->audit->log($user, '2fa.failed', 'user', $user->id, null, [
            'context' => $context,
            'attempts' => $attempts,
            'ip' => $request->ip(),
        ]);

        if ($attempts >= $limit) {
            $this->audit->log($user, '2fa.locked', 'user', $user->id, null, [
                'context' => $context,
                'attempts' => $attempts,
            ]);

            if ($user->employee !== null) {
                $this->notifications->notify(
                    $user->employee,
                    'security.2fa_locked',
                    __('Блокировка 2FA'),
                    __('Обнаружено несколько неверных кодов подтверждения. Если это были не вы, смените пароль.'),
                    $user,
                );
            }

            abort(429, __('Слишком много неверных кодов 2FA. Попробуйте позже.'));
        }

        throw ValidationException::withMessages(['code' => __('Неверный код.')]);
    }
}
