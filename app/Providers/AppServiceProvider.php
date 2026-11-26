<?php

namespace App\Providers;

use App\Domains\Ai\Services\BumiPersonaService;
use App\Domains\Ai\Services\BumiRoleService;
use App\Domains\Attendance\Events\AttendanceRecorded;
use App\Domains\Attendance\Listeners\NotifyOnLateArrival;
use App\Http\Middleware\BoostBrowserLogsGuard;
use App\Models\BiChatMessage;
use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Policies\BiChatMessagePolicy;
use App\Policies\BiDashboardPolicy;
use App\Policies\BiWidgetPolicy;
use App\Support\EnumLabels;
use App\Support\PermissionService;
use App\Support\Queue\WorkerHeartbeat;
use Illuminate\Auth\Events\Login;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PermissionService::class);
    }

    public function boot(): void
    {
        // TEST-04: в production генерируем только HTTPS-ссылки даже если
        // запрос пришёл от proxy без корректного X-Forwarded-Proto.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Bootstrap-разметка пейджера (Tabler) вместо tailwind-видов Laravel по умолчанию
        Paginator::defaultView('pagination::tabler');
        Paginator::defaultSimpleView('pagination::tabler');

        // п.13 ТЗ: уведомления об опозданиях
        Event::listen(AttendanceRecorded::class, NotifyOnLateArrival::class);
        Event::listen(Login::class, function ($event): void {
            session()->put('auth_version', (int) $event->user->auth_version);
        });

        // Heartbeat пулов воркеров: любое реально обработанное задание
        // подтверждает, что воркер жив и исполняет текущий код.
        Queue::before(static function (JobProcessing $event): void {
            /** @var WorkerHeartbeat $heartbeat */
            $heartbeat = app(WorkerHeartbeat::class);
            $heartbeat->beat($heartbeat->poolForQueue((string) $event->job->getQueue()));
        });

        // Подписи enum-ов хранятся в виде русских исходных строк — прогоняем их
        // через перевод, иначе воронка CRM остаётся русской среди таджикского UI.
        View::share('crm_label', static fn (?string $group = null, ?string $value = null) => $group === null ? '' : __(EnumLabels::label($group, $value)));

        // Настроение Bumi вычисляется сервисом, а не случайным JS-таймером:
        // все точке входа получают одну и ту же осмысленную реплику.
        View::composer(['layouts.tabler', 'layouts.auth', 'welcome'], static function ($view): void {
            $view->with('bumi', app(BumiPersonaService::class)->current())
                ->with('bumiVoice', config('corpms.bumi.conversation'))
                ->with('bumiRole', app(BumiRoleService::class)->current());
        });

        // BI-политики связывают право bi.* с принадлежностью объекта тенанту.
        Gate::policy(BiDashboard::class, BiDashboardPolicy::class);
        Gate::policy(BiWidget::class, BiWidgetPolicy::class);
        Gate::policy(BiChatMessage::class, BiChatMessagePolicy::class);

        // Blade-гейт @can('checkBoxPermission', 'slug.permission'):
        // UI-кнопки гейтятся той же матрицей прав, что и HTTP-middleware.
        // Без before-замыкания все кнопки управления пропадали у всех, включая админа.
        Gate::before(function ($user, $ability, array $parameters) {
            if ($ability !== 'checkBoxPermission') {
                return null; // прочие способы — стандартные правила Gate
            }

            $permission = $parameters[0] ?? null;

            return is_string($permission) && $permission !== ''
                ? app(PermissionService::class)->check($user, $permission)
                : false;
        });

        // SEC-04: vendor-роут Boost регистрируется до загрузки наших routes;
        // навешиваем guard после полной регистрации всех маршрутов.
        $this->app->booted(function (): void {
            $route = Route::getRoutes()->getByName('boost.browser-logs');
            if ($route !== null) {
                $route->middleware(['throttle:10,1', BoostBrowserLogsGuard::class]);
            }
        });
    }
}
