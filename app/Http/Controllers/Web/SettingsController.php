<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Ai\Services\AiProviderService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AiEndpointGuard;
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
        protected AiProviderService $providers,
    ) {}

    public function index(): View
    {
        $values = $this->settings->currentValues();

        $env = [
            'telegram_bot' => config('corpms.telegram.bot_token') !== '' ? __('настроен') : __('не задан'),
            'telegram_webhook_secret' => config('corpms.telegram.webhook_secret') !== '' ? __('настроен') : __('не задан'),
            'ai_base_url' => config('corpms.ai.base_url') !== '' ? __('настроен') : __('не задан'),
        ];

        return view('settings.index', [
            'settings' => $values,
            'env' => $env,
            'aiProviders' => $this->providers->providers(),
            'aiActiveProviderId' => $this->providers->active()?->id,
            'canManage' => $this->canManage(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($this->canManage() === false) {
            abort(403, __('Только просмотр'));
        }

        $input = $request->input('settings');
        if (! is_array($input)) {
            $input = [];
        }
        if ($input === [] && ! $request->has('ai_providers')) {
            return redirect()->route('settings.index')->with('status', __('Нечего сохранять'));
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
                // AI-04/SEC-08: только whitelist/публичный хост, без IP и redirect-ловушек.
                $rule = [
                    'nullable', 'string', 'url:http,https', 'max:2000',
                    function (string $attribute, mixed $value, callable $fail): void {
                        if (is_string($value) && $value !== '' && ! AiEndpointGuard::isAllowed($value)) {
                            $fail(__('Адрес провайдера AI должен быть публичным HTTPS-хостом из whitelist; IP и внутренние сети запрещены.'));
                        }
                    },
                ];
            }
            $validator = Validator::make(['value' => $input[$key]], ['value' => $rule], [], ['value' => $meta['name']]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['settings.'.$key => $validator->errors()->first()]);
            }
        }

        [$providerRows, $activeRef] = $this->validateProviders($request);

        return DB::transaction(function () use ($input, $request, $old, $new, $providerRows, $activeRef): RedirectResponse {

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

            $providerAudit = $request->has('ai_providers')
                ? $this->providers->sync($providerRows, $activeRef)
                : null;

            if ($new === [] && $providerAudit === null) {
                return redirect()->route('settings.index')->with('status', __('Нечего сохранять'));
            }

            $this->audit->log($request->user(), 'settings.updated', 'setting', null, $old, [
                ...$new,
                ...($providerAudit === null ? [] : [
                    'ai_providers_before' => $providerAudit['old'],
                    'ai_providers_after' => $providerAudit['new'],
                ]),
            ]);

            return redirect()->route('settings.index')->with('status', __('Настройки сохранены'));
        });
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    protected function validateProviders(Request $request): array
    {
        if (! $request->has('ai_providers')) {
            return [[], null];
        }

        $raw = $request->input('ai_providers');
        if (! is_array($raw) || count($raw) > 20) {
            throw ValidationException::withMessages(['ai_providers' => __('Можно добавить не более 20 провайдеров.')]);
        }

        $rows = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['ai_providers.'.$index => __('Некорректная запись провайдера.')]);
            }

            $validator = Validator::make($row, [
                'id' => ['sometimes', 'integer', 'min:1'],
                'name' => ['required', 'string', 'max:120'],
                'base_url' => ['required', 'string', 'url:http,https', 'max:500'],
                'api_key' => ['required_without:id', 'nullable', 'string', 'max:2000'],
                'models' => ['required', 'string', 'max:1000'],
                'default_model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]{1,120}$/'],
                'image_model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]{1,120}$/'],
                'embedding_model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]{1,120}$/'],
                'timeout' => ['required', 'integer', 'min:5', 'max:300'],
            ], [], [
                'name' => __('Название провайдера'),
                'base_url' => __('Адрес провайдера'),
                'api_key' => __('API-ключ'),
                'models' => __('Модели'),
            ]);

            if ($validator->fails()) {
                throw ValidationException::withMessages(['ai_providers.'.$index => $validator->errors()->first()]);
            }

            if (! AiEndpointGuard::isAllowed((string) $row['base_url'])) {
                throw ValidationException::withMessages([
                    'ai_providers.'.$index => __('Адрес провайдера AI должен быть публичным HTTPS-хостом из whitelist.'),
                ]);
            }

            $rows[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => (string) $row['name'],
                'base_url' => (string) $row['base_url'],
                'api_key' => (string) ($row['api_key'] ?? ''),
                'models' => (string) $row['models'],
                'default_model' => (string) ($row['default_model'] ?? ''),
                'image_model' => (string) ($row['image_model'] ?? ''),
                'embedding_model' => (string) ($row['embedding_model'] ?? ''),
                'timeout' => (int) $row['timeout'],
            ];
        }

        $activeRef = $request->input('ai_active_provider');
        if ($activeRef !== null) {
            $activeRef = (string) $activeRef;
            if (! preg_match('/^(?:\d+|new:\d+)$/', $activeRef)) {
                throw ValidationException::withMessages(['ai_active_provider' => __('Некорректный активный провайдер.')]);
            }
        }

        return [$rows, $activeRef];
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
