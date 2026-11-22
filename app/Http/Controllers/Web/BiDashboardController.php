<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Bi\Jobs\RebuildBiMart;
use App\Domains\Bi\Services\BiService;
use App\Http\Controllers\Controller;
use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Shared\Enums\BiWidgetKind;
use App\Support\PermissionService;
use App\Support\SingleFlightLock;
use App\Support\TenantGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        [$from, $to] = $this->period($month);

        $default = $this->bi->ensureDefaultDashboard($companyId, $me->user_id);

        $dashboards = BiDashboard::query()
            ->where('company_id', $companyId)
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
            ->map(fn (BiWidget $widget): array => [
                'widget' => $widget,
                'data' => $this->bi->widgetData($widget, $companyId, $from, $to),
            ]);

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
            'canManage' => $this->permissions->check($request->user(), 'bi.manage'),
        ]);
    }

    /** Создание дашборда. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

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

    /** Добавление виджета на дашборд. */
    public function storeWidget(Request $request, BiDashboard $dashboard): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);

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

    /** Удаление виджета. */
    public function destroyWidget(Request $request, BiDashboard $dashboard, BiWidget $widget): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($dashboard, $me);

        if ((int) $widget->dashboard_id !== (int) $dashboard->id) {
            abort(404, __('Виджет не относится к дашборду'));
        }

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
