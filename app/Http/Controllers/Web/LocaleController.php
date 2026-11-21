<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, SetLocale::SUPPORTED, true)) {
            abort(404);
        }

        if ($request->user()) {
            $request->user()->forceFill(['locale' => $locale])->save();
        }

        $request->session()->put('locale', $locale);

        return redirect()->back()->withCookie(cookie('locale', $locale, 60 * 24 * 365));
    }
}