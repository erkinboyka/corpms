<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Bi\Jobs\RebuildBiMart;
use App\Domains\Bi\Services\BiService;
use App\Domains\DashboardGen\Jobs\GenerateAiDashboard;
use App\Domains\DashboardGen\Services\AiClient;
use App\Domains\DashboardGen\Services\BiAnalystChat;
use App\Domains\DashboardGen\Services\WidgetCatalog;
use App\Domains\DashboardGen\Services\WidgetSpecCompiler;
use App\Domains\DashboardGen\Services\BiInstructionGuard;
use App\Domains\DashboardGen\Services\WidgetQueryRunner;
use App\Http\Controllers\Controller;
use App\Models\BiChatMessage;
use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Shared\Enums\BiWidgetKind;
use App\Support\PermissionService;
use App\Support\SingleFlightLock;
use App\Support\TenantGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * BI-дашборды (Этап E6): витрина, KPI/тренды, кастомные виджеты и прогноз.
 */
class BiDashboardController extends Controller
{
    public function __construct(
        protected BiService $bi,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Список дашбордов и витрина месяца по умолчанию. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        Gate::authorize('viewAny', BiDashboard::class);

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        [$from, $to] = $this->period($month);

        $default = $this->bi->ensureDefaultDashboard($companyId, $me->user_id);

        $dashboards = BiDashboard::query()
            ->forCompany($companyId)
            ->withCount('widgets')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('bi.index', [
            'dashboards' => $dashboards,
            'default' => $default,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'totals' => $this->bi->totals($companyId, $from, $to),
            'catalog' => $this->bi->catalog(),
            'canManage' => $this->permissions->check($request->user(), 'bi.manage'),
        ]);
    }

    /** Дашборд с виджетами, трендами и прогнозом за период. */
    public function show(Request $request, BiDashboard $dashboard): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);
        Gate::authorize('view', $dashboard);

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        [$from, $to] = $this->period($month);

        $widgets = $dashboard->widgets()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (BiWidget $widget) use ($dashboard, $companyId, $from, $to): array {
                // SQL-виджет, сгенерированный ИИ: данные из query_spec, не из витрины
                if ($widget->isAiWidget()) {
                    return [
                        'widget' => $widget,
                        'data' => $this->aiWidgetData($widget, $dashboard, $companyId),
                    ];
                }

                return [
                    'widget' => $widget,
                    'data' => $this->bi->widgetData($widget, $companyId, $from, $to),
                ];
            });

        $forecasts = [];

        foreach (['finance_income', 'finance_expense', 'deals_won_amount', 'invoices_paid'] as $code) {
            $forecast = $this->bi->forecast($companyId, $code);

            if ($forecast !== []) {
                $forecasts[$code] = $forecast;
            }
        }

        return view('bi.show', [
            'dashboard' => $dashboard,
            'widgets' => $widgets,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'totals' => $this->bi->totals($companyId, $from, $to),
            'forecasts' => $forecasts,
            'catalog' => $this->bi->catalog(),
            'kinds' => BiWidgetKind::cases(),
            'widgetFamilies' => app(WidgetCatalog::class)->families(),
            'spanOptions' => [4, 6, 12],
            'canManage' => $this->permissions->check($request->user(), 'bi.manage'),
        ]);
    }

    /**
     * Живой прогресс генерации для Bumi на странице дашборда.
     * Отдаёт только статусы и названия виджетов — без SQL и данных.
     */
    public function generationStatus(Request $request, BiDashboard $dashboard): JsonResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);
        Gate::authorize('view', $dashboard);

        $widgets = $dashboard->widgets()
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'title', 'status', 'query_spec'])
            ->map(fn (BiWidget $widget): array => [
                'id' => (int) $widget->id,
                'title' => (string) $widget->title,
                'status' => (string) $widget->status,
                'ready' => $widget->status === 'ready' && filled($widget->query_spec),
            ])
            ->all();

        return response()->json([
            'status' => (string) $dashboard->status,
            'instruction' => $dashboard->instruction,
            'last_error' => $dashboard->last_error,
            'widgets' => $widgets,
            'ready' => count(array_filter($widgets, static fn (array $widget): bool => $widget['ready'])),
            'total' => count($widgets),
        ]);
    }

    /**
     * Bumi в режиме /bi: инструментальные изменения текущего дашборда.
     * Вопрос проверяется на BI-релевантность до вызова модели.
     */
    public function chat(Request $request, BiDashboard $dashboard, BiAnalystChat $chat): JsonResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);
        Gate::authorize('update', $dashboard);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ], [], ['message' => __('запрос')]);

        if (! app(BiInstructionGuard::class)->isRelevant($validated['message'])) {
            return response()->json([
                'ok' => false,
                'error' => __('Запрос не относится к данным BI. Спросите о метриках, виджетах или показателях компании.'),
            ], 422);
        }

        return response()->json($chat->reply($request->user(), $dashboard, $validated['message']));
    }

    /** Откат изменений, сделанных Bumi в одном ответе. */
    public function undoChat(Request $request, BiDashboard $dashboard, BiChatMessage $message, BiAnalystChat $chat): JsonResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);
        Gate::authorize('update', $dashboard);

        if ((int) $message->dashboard_id !== (int) $dashboard->id) {
            abort(404, __('Сообщение не относится к дашборду'));
        }

        return response()->json(['ok' => true] + $chat->undo($request->user(), $dashboard, $message));
    }

    /** Оценка ответа Bumi (1–5). */
    public function rateChat(Request $request, BiChatMessage $message, BiAnalystChat $chat): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        return response()->json($chat->rate($request->user(), $message, (int) $validated['rating']));
    }

    /** Создание дашборда. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        Gate::authorize('create', BiDashboard::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [], ['name' => __('название')]);

        $dashboard = BiDashboard::query()->create([
            'company_id' => $me->company_id,
            'name' => $validated['name'],
            'is_default' => false,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'bi.dashboard_created', 'bi_dashboard', $dashboard->id, null, [
            'name' => $dashboard->name,
        ]);

        return redirect()->route('bi.dashboards.show', $dashboard)->with('status', __('Дашборд «').$dashboard->name.__('» создан'));
    }

    /**
     * Генерация дашборда по текстовой инструкции ИИ: создаётся дашборд
     * в статусе generating, сборка уходит в очередь.
     */
    public function generate(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        Gate::authorize('create', BiDashboard::class);

        $validated = $request->validate([
            'instruction' => ['required', 'string', 'max:2000'],
        ], [], ['instruction' => __('запрос')]);

        if (! app(BiInstructionGuard::class)->isRelevant($validated['instruction'])) {
            return back()->withInput()->withErrors([
                'instruction' => __('Запрос не относится к данным BI. Спросите о доходах, расходах, сделках, клиентах, задачах, метриках или других показателях компании.'),
            ]);
        }

        if (! app(AiClient::class)->enabled()) {
            return back()->with('error', __('AI не настроен: проверьте AI_BASE_URL и AI_API_KEY.'));
        }

        $lock = \Illuminate\Support\Facades\Cache::lock('bi-generate:company:'.$me->company_id, 15);

        if (!$lock->get()) {
            return back()->with('error', __('Генерация уже запущена. Дождитесь завершения текущего запроса.'));
        }

        $dashboard = null;

        try {
            $dashboard = BiDashboard::query()->create([
                'company_id' => $me->company_id,
                'name' => __('Генерация дашборда'),
                'is_default' => false,
                'created_by' => $me->user_id,
                'origin' => 'ai',
                'status' => 'generating',
                'instruction' => $validated['instruction'],
            ]);

            GenerateAiDashboard::dispatch(
                $dashboard->id,
                (int) $me->company_id,
                (int) $me->user_id,
                $validated['instruction'],
            );

            $this->audit->log($request->user(), 'bi.dashboard_generate_queued', 'bi_dashboard', $dashboard->id, null, [
                'instruction' => $validated['instruction'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            if ($dashboard !== null) {
                $dashboard->update(['status' => 'error', 'last_error' => Str::limit($e->getMessage(), 1000, '')]);
            }

            return back()->with('error', __('Не удалось поставить генерацию в очередь.'));
        } finally {
            $lock->release();
        }

        return redirect()->route('bi.dashboards.show', $dashboard)
            ->with('status', __('Генерация дашборда запущена — страница обновится сама.'));
    }

    /**
     * Данные SQL-виджета: выполняет query_spec и отдаёт форму контента
     * фронтендеру (BiWidgets.render). Ошибки не роняют страницу — виджет
     * показывает текст ошибки сам.
     *
     * @return array<string, mixed>
     */
    private function aiWidgetData(BiWidget $widget, BiDashboard $dashboard, int $companyId): array
    {
        $base = [
            'family' => $widget->widget_family,
            'type' => $widget->widget_type,
            'status' => $widget->status,
            'last_error' => $widget->last_error,
        ];

        if ($widget->status === 'generating' || $widget->status === 'pending') {
            return $base + ['content' => null];
        }

        $spec = $widget->query_spec;

        if (!is_array($spec) || $spec === []) {
            return $base + ['content' => null, 'last_error' => $widget->last_error ?? __('Спецификация запроса отсутствует.')];
        }

        // Фильтры Bumi применяются поверх исходной спецификации: готовый SQL
        // не правится строками, а пересобирается компилятором.
        $filters = [
            ...(is_array($dashboard->filters ?? null) ? $dashboard->filters : []),
            ...(is_array($widget->filters ?? null) ? $widget->filters : []),
        ];

        if ($filters !== []) {
            $spec = WidgetSpecCompiler::forCurrentConnection()->apply($spec, $filters);
        }

        $runner = new WidgetQueryRunner(companyId: $companyId);
        $result = $runner->run($spec, (string) $widget->widget_family, $widget->widget_type);

        if (!($result['ok'] ?? false)) {
            return $base + ['content' => null, 'last_error' => (string) ($result['error'] ?? __('Ошибка запроса.'))];
        }

        return $base + [
            'content' => $result['data'],
            'meta' => $result['meta'] ?? [],
            'last_error' => null,
            'has_data' => $this->widgetHasData($result['data']),
        ];
    }

    /**
     * Признак того, что в ответе виджета есть хоть одна точка/строка.
     * Агрегат без GROUP BY возвращает одну строку с NULL-меткой и нулём —
     * это не данные, а пустое состояние виджета.
     */
    private function widgetHasData(array $content): bool
    {
        foreach (['rows', 'counters', 'categories', 'points', 'x', 'y'] as $key) {
            if (!empty($content[$key])) {
                return true;
            }
        }

        $labels = $content['labels'] ?? [];

        if (is_array($labels) && $labels !== []) {
            foreach ($labels as $label) {
                $label = trim((string) $label);

                if ($label !== '' && $label !== '—' && $label !== '-') {
                    return true;
                }
            }

            return false;
        }

        return !empty($content['series']);
    }

    /** Добавление виджета на дашборд. */
    public function storeWidget(Request $request, BiDashboard $dashboard): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);
        Gate::authorize('create', BiWidget::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(BiWidgetKind::class)],
            'metric_code' => ['required', 'string', Rule::in(array_keys($this->bi->catalog()))],
        ], [], ['title' => __('название'), 'metric_code' => __('метрика')]);

        $widget = $dashboard->widgets()->create([
            'title' => $validated['title'],
            'kind' => BiWidgetKind::from($validated['kind']),
            'metric_code' => $validated['metric_code'],
            'position' => (int) $dashboard->widgets()->max('position') + 1,
        ]);

        $this->audit->log($request->user(), 'bi.widget_created', 'bi_dashboard', $dashboard->id, null, [
            'widget_id' => $widget->id,
            'metric_code' => $widget->metric_code,
        ]);

        return back()->with('status', __('Виджет «').$widget->title.__('» добавлен'));
    }

    /**
     * Точечное редактирование SQL-виджета: заголовок, семейство/тип, ширина
     * и оформление. SQL и tenant-ограничения в query_spec не трогаются —
     * меняется только визуальная часть спецификации.
     */
    public function updateWidget(Request $request, BiDashboard $dashboard, BiWidget $widget): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);

        if ((int) $widget->dashboard_id !== (int) $dashboard->id) {
            abort(404, __('Виджет не относится к дашборду'));
        }

        Gate::authorize('update', $widget);

        $catalog = app(WidgetCatalog::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'widget_family' => ['required', Rule::in($catalog->names())],
            'widget_type' => ['nullable', 'string', 'max:32'],
            'span' => ['required', 'integer', Rule::in([4, 6, 12])],
            'presentation' => ['nullable', 'json'],
        ], [], [
            'title' => __('название'),
            'widget_family' => __('семейство'),
            'widget_type' => __('тип'),
            'span' => __('ширина'),
        ]);

        $family = (string) $validated['widget_family'];
        $type = $validated['widget_type'] ?? null;
        $type = is_string($type) && $type !== '' ? $type : null;

        if (!$catalog->hasType($family, $type)) {
            $type = $catalog->defaultType($family);
        }

        $payload = [
            'title' => (string) $validated['title'],
            'widget_family' => $family,
            'widget_type' => $type,
            'span' => (int) $validated['span'],
        ];

        if (isset($validated['presentation'])) {
            $decoded = json_decode((string) $validated['presentation'], true);

            if (is_array($widget->query_spec)) {
                $spec = $widget->query_spec;
                $spec['presentation'] = is_array($decoded) ? $decoded : [];
                $payload['query_spec'] = $spec;
            }
        }

        $old = $widget->only(array_keys($payload));

        $widget->update($payload);

        $this->audit->log($request->user(), 'bi.widget_updated', 'bi_dashboard', $dashboard->id, $old, $payload);

        return back()->with('status', __('Виджет «').$widget->title.__('» обновлён'));
    }

    /** Удаление виджета. */
    public function destroyWidget(Request $request, BiDashboard $dashboard, BiWidget $widget): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);

        if ((int) $widget->dashboard_id !== (int) $dashboard->id) {
            abort(404, __('Виджет не относится к дашборду'));
        }

        Gate::authorize('delete', $widget);

        $widget->delete();

        $this->audit->log($request->user(), 'bi.widget_deleted', 'bi_dashboard', $dashboard->id, null, [
            'widget_id' => $widget->id,
        ]);

        return back()->with('status', __('Виджет удалён'));
    }

    /** Пересборка витрины за месяц (BE-06/PERF-06: через очередь). */
    public function rebuild(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $month = (string) $request->input('month', now()->format('Y-m'));
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        $companyId = (int) $me->company_id;
        $lockKey = 'bi.rebuild:'.$companyId;
        $lockToken = SingleFlightLock::acquire($lockKey);

        if ($lockToken === null) {
            abort(429, 'Пересборка витрины уже выполняется.');
        }

        try {
            RebuildBiMart::dispatch(
                $companyId,
                (int) $request->user()->id,
                $month,
                $lockKey,
                $lockToken,
            );
        } catch (\Throwable $exception) {
            SingleFlightLock::release($lockKey, $lockToken);

            throw $exception;
        }

        return back()->with('status', __('Пересборка витрины запущена'));
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function period(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');

        return [$start->startOfMonth()->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
