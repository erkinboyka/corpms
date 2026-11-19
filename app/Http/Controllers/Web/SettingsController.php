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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

        // Validate the complete request before changing any setting.
        foreach ($this->settings->definitions() as $key => $meta) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $rule = match ($meta['type']) {
                'bool' => ['required', 'boolean'],
                'int' => ['required', 'integer', 'min:1', 'max:3600'],
                default => ['nullable', 'string', 'max:2000'],
            };
            $limits = [
                'corpms.tasks.max_subtask_depth' => [1, 20],
                'corpms.documents.max_file_size_mb' => [1, 100],
                'corpms.documents.max_file_count' => [1, 100],
                'corpms.signature.otp_ttl' => [1, 60],
                'corpms.signature.qr_token_ttl_hours' => [1, 168],
                'corpms.notifications.daily_summary_hour' => [0, 23],
                'corpms.ai.timeout' => [1, 300],
            ];
            if (isset($limits[$key])) {
                [$min, $max] = $limits[$key];
                $rule = ['required', 'integer', 'min:'.$min, 'max:'.$max];
            }
            if ($key === 'corpms.ai.base_url') {
                $rule = ['nullable', 'string', 'url:http,https', 'max:2000'];
            }
            $validator = Validator::make(['value' => $input[$key]], ['value' => $rule], [], ['value' => $meta['name']]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['settings.'.$key => $validator->errors()->first()]);
            }
        }

        return DB::transaction(function () use ($input, $request, $old, $new): RedirectResponse {

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
        });
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
