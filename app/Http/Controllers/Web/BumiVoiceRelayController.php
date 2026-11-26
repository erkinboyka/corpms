<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Тихий iframe на доверенном localhost-origin: браузер разрешает микрофон
 * только на HTTPS/localhost, поэтому с http://corpms.local аудио ловит этот
 * endpoint, а родительская страница загружает транскрипт через свой CSRF.
 *
 * Endpoint публичный, но не отдаёт и не принимает текст/данные компании:
 * только подписанный nonce и src=<audio blob> родительскому origin из белого
 * списка. CSP frame-ancestors выставляется в SecurityHeaders.
 */
class BumiVoiceRelayController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless((string) config('corpms.bumi.conversation.relay_url', '') !== '', 404);

        $parent = (string) $request->query('parent', '');
        $nonce = (string) $request->query('nonce', '');
        $allowed = (array) config('corpms.bumi.conversation.parent_origins', []);

        abort_unless(in_array($parent, $allowed, true), 403);
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,64}$/', $nonce) === 1, 403);

        return view('bumi.voice-relay', [
            'parentOrigin' => $parent,
            'nonce' => $nonce,
        ]);
    }
}
