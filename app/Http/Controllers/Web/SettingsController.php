<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PermissionService;
use App\Support\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Настройки системы (п.70 ТЗ): просмотр и правка устойчивых значений.
 */
class SettingsController extends Controller
{
    public function __construct(
        protected SettingsService $settings,
        protected AuditService $audit,
    ) {}

    public function index(): View
    {
        $values = $this->settings->currentValues();

        $env = [
            'telegram_bot' => config('corpms.telegram.bot_token') !== '' ? 'настроен' : 'не задан',
            'telegram_webhook_secret' => config('corpms.telegram.webhook_secret') !== '' ? 'настроен' : 'не задан',
            'ai_base_url' => config('corpms.ai.base_url') !== '' ? 'настроен' : 'не задан',
        ];

        return view('settings.index', [
            'settings' => $values,
            'env' => $env,
            'canManage' => $this->canManage(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($this->canManage() === false) {
            abort(403, 'Только просмотр');
        }

        $input = $request->input('settings');
        if (! is_array($input)) {
            return redirect()->route('settings.index')->with('status', 'Нечего сохранять');
        }

        $old = [];
        $new = [];

        foreach ($this->settings->definitions() as $key => $meta) {
            if (! \array_key_exists($key, $input)) {
                continue;
            }

            $raw = match ($meta['type']) {
                'bool' => $input[$key] ? true : false,
                'int' => is_scalar($input[$key]) ? (string) (int) $input[$key] : null,
                default => is_scalar($input[$key]) ? (string) $input[$key] : null,
            };

            if ($raw === null) {
                continue;
            }

            if ($meta['type'] === 'secret' && trim($raw) === '') {
                continue;
            }

            $old[$key] = $this->maskSecret($this->settings->get($key), $meta['type']);
            $this->settings->set($key, match ($meta['type']) {
                'bool' => (bool) $raw,
                'int' => (int) $raw,
                default => (string) $raw,
            });
            $new[$key] = $this->maskSecret($this->settings->get($key), $meta['type']);
        }

        if ($new === []) {
            return redirect()->route('settings.index')->with('status', 'Нечего сохранять');
        }

        $this->audit->log($request->user(), 'settings.updated', 'setting', null, $old, $new);

        return redirect()->route('settings.index')->with('status', 'Настройки сохранены');
    }

    /**
     * Секретные значения (API-ключи, токены) не должны попадать в audit_logs
     * открытым текстом — в журнал пишется маска, а не само значение.
     */
    protected function maskSecret(mixed $value, string $type): mixed
    {
        if ($type !== 'secret' || $value === null || $value === '') {
            return $value;
        }

        return '••••••';
    }

    protected function canManage(): bool
    {
        $user = auth()->user();
        \assert($user instanceof User);

        return app(PermissionService::class)->check($user, 'settings.manage');
    }
}
