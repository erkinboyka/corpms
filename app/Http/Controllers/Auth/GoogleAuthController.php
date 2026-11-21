<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController
{
    public function redirect()
    {
        $config = config('services.google');
        if (empty($config['client_id'])) {
            return redirect()->route('login')->with('error', __('Google OAuth не настроен.'));
        }
        $state = Str::random(40);
        session(['google_oauth_state' => $state]);
        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'] ?: route('google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request)
    {
        if ($request->input('state') !== session('google_oauth_state')) {
            return redirect()->route('login')->with('error', __('Неверный state.'));
        }
        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('login')->with('error', __('Код авторизации не получен.'));
        }
        $config = config('services.google');
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect'] ?: route('google.callback'),
            'grant_type' => 'authorization_code',
        ]);
        if (! $tokenResponse->successful()) {
            return redirect()->route('login')->with('error', __('Не удалось получить токен Google.'));
        }
        $accessToken = $tokenResponse->json('access_token');
        $userInfo = Http::withToken($accessToken)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $userInfo->successful()) {
            return redirect()->route('login')->with('error', __('Не удалось получить профиль Google.'));
        }
        $data = $userInfo->json();
        $googleId = $data['sub'] ?? null;
        $email = $data['email'] ?? null;
        if (! $googleId || ! $email) {
            return redirect()->route('login')->with('error', __('Неполные данные Google.'));
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
        request()->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }
}