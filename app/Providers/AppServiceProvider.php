<?php

namespace App\Providers;

use App\Domains\Attendance\Events\AttendanceRecorded;
use App\Domains\Attendance\Listeners\NotifyOnLateArrival;
use App\Http\Middleware\BoostBrowserLogsGuard;
use App\Support\EnumLabels;
use App\Support\PermissionService;
use Illuminate\Auth\Events\Login;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        // Подписи enum-ов хранятся в виде русских исходных строк — прогоняем их
        // через перевод, иначе воронка CRM остаётся русской среди таджикского UI.
        View::share('crm_label', static fn (?string $group = null, ?string $value = null) => $group === null ? '' : __(EnumLabels::label($group, $value)));

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
