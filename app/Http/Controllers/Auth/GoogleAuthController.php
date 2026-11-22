<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController
{
    /** Максимальный срок жизни state/PKCE-верификаера. */
    private const STATE_TTL_SECONDS = 600;

    public function redirect(Request $request): RedirectResponse
    {
        $config = config('services.google');
        if (empty($config['client_id'])) {
            return redirect()->route('login')->with('error', __('Google OAuth не настроен.'));
        }

        $state = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        // Всё одноразовое: state, его возраст и PKCE-верификаер удаляются
        // в callback до обмена кода, поэтому повторный callback не пройдёт.
        $request->session()->put([
            'google_oauth_state' => $state,
            'google_oauth_state_at' => now()->getTimestamp(),
            'google_oauth_code_verifier' => $verifier,
        ]);

        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'] ?: route('google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        // pull() удаляет значения даже при последующей ошибке — state нельзя
        // использовать дважды, включая случай `null === null` из старого кода.
        $state = $request->input('state');
        $expected = $request->session()->pull('google_oauth_state');
        $issuedAt = $request->session()->pull('google_oauth_state_at');
        $verifier = $request->session()->pull('google_oauth_code_verifier');

        if (! is_string($state) || ! is_string($expected) || $expected === ''
            || ! hash_equals($expected, $state)
            || ! is_numeric($issuedAt)
            || now()->getTimestamp() - (int) $issuedAt > self::STATE_TTL_SECONDS
            || ! is_string($verifier) || $verifier === '') {
            return redirect()->route('login')->with('error', __('Неверный или устаревший state.'));
        }

        $code = $request->input('code');
        if (! is_string($code) || $code === '') {
            return redirect()->route('login')->with('error', __('Код авторизации не получен.'));
        }

        $config = config('services.google');
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect'] ?: route('google.callback'),
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ]);
        if (! $tokenResponse->successful()) {
            return redirect()->route('login')->with('error', __('Не удалось получить токен Google.'));
        }

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            return redirect()->route('login')->with('error', __('Не удалось получить токен Google.'));
        }

        $userInfo = Http::withToken($accessToken)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $userInfo->successful()) {
            return redirect()->route('login')->with('error', __('Не удалось получить профиль Google.'));
        }

        $data = $userInfo->json();
        $googleId = $data['sub'] ?? null;
        $email = $data['email'] ?? null;
        if (! is_string($googleId) || $googleId === '' || ! is_string($email) || $email === '') {
            return redirect()->route('login')->with('error', __('Неполные данные Google.'));
        }

        // SEC-07: доверяем только подтверждённому Google email; при
        // корпоративном IdP дополнительно ограничиваем домен (hd).
        if (($data['email_verified'] ?? false) !== true) {
            return redirect()->route('login')->with('error', __('Email не подтверждён Google.'));
        }
        $allowedDomain = $config['allowed_domain'] ?? null;
        if (is_string($allowedDomain) && $allowedDomain !== '') {
            $hostedDomain = $data['hd'] ?? null;
            if (! is_string($hostedDomain) || ! hash_equals($allowedDomain, $hostedDomain)) {
                return redirect()->route('login')->with('error', __('Домен Google не разрешён.'));
            }
        }

        $user = User::where('google_id', $googleId)->orWhere('email', $email)->first();
        if (! $user) {
            // Создаём учётку без сотрудника; админ привяжет позже.
            $user = User::create([
                'name' => $data['name'] ?? $email,
                'email' => $email,
                'password' => Str::random(32),
                'google_id' => $googleId,
                'auth_provider' => 'google',
            ]);
        } else {
            $user->forceFill([
                'google_id' => $googleId,
                'auth_provider' => 'google',
            ])->save();
        }

        // Проверка активности сотрудника.
        $employee = $user->employee;
        if ($employee && $employee->status !== 'active') {
            return redirect()->route('login')->with('error', __('Учётная запись неактивна.'));
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
