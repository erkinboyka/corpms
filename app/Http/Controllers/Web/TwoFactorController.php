<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Support\QrCodeGenerator;
use App\Support\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TwoFactorController
{
    public function __construct(private TwoFactorService $twoFactor) {}

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
        $user = \App\Models\User::find($userId);
        if (! $user || ! $user->two_factor_secret) {
            return redirect()->route('login');
        }
        $secret = $this->twoFactor->decryptSecret($user->two_factor_secret);
        if (! $this->twoFactor->verify($secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('Неверный код.')]);
        }
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
        $secret = $request->session()->get('2fa:pending_secret');
        if (! $secret) {
            return redirect()->route('profile.2fa');
        }
        if (! $this->twoFactor->verify($secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('Неверный код.')]);
        }
        $user->forceFill([
            'two_factor_secret' => $this->twoFactor->encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->forget('2fa:pending_secret');
        return redirect()->route('profile.2fa')->with('status', __('2FA включена'));
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! \Illuminate\Support\Facades\Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('Неверный пароль.')]);
        }
        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        return redirect()->route('profile.2fa')->with('status', __('2FA отключена'));
    }
}