<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Notifications\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Колокольчик уведомлений (п.13, 58 ТЗ).
 */
class NotificationController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $items = $this->notifications->recentFor($me, 50);
        $unread = $this->notifications->unreadCount($me);

        return view('notifications.index', compact('items', 'unread'));
    }

    public function markRead(Request $request, AppNotification $notification): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        // чужое уведомление (даже сотрудника своей компании) — 403, не 500
        if ((int) $notification->recipient_id !== (int) $me->id) {
            abort(403, 'Чужое уведомление');
        }

        $this->notifications->markRead($notification);
        $notification->refresh();

        return back()->with('status', 'Отмечено прочитанным');
    }
}
