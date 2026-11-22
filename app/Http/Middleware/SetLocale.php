<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    public const SUPPORTED = ['ru', 'en', 'tg', 'uz'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        if ($request->user() && isset($request->user()->locale) && in_array($request->user()->locale, self::SUPPORTED, true)) {
            $locale = $request->user()->locale;
        } elseif ($request->session()->has('locale') && in_array($request->session()->get('locale'), self::SUPPORTED, true)) {
            $locale = $request->session()->get('locale');
        } elseif ($request->cookie('locale') && in_array($request->cookie('locale'), self::SUPPORTED, true)) {
            $locale = $request->cookie('locale');
        }

        if ($locale !== null) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
