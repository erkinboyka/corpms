<?php

namespace App\Providers;

use App\Domains\Attendance\Events\AttendanceRecorded;
use App\Domains\Attendance\Listeners\NotifyOnLateArrival;
use App\Support\EnumLabels;
use App\Support\PermissionService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionService::class);
    }

    public function boot(PermissionService $permissions): void
    {
        // Bootstrap-разметка пейджера (Tabler) вместо tailwind-видов Laravel по умолчанию
        Paginator::defaultView('pagination::tabler');
        Paginator::defaultSimpleView('pagination::tabler');

        // п.13 ТЗ: уведомления об опозданиях
        Event::listen(AttendanceRecorded::class, NotifyOnLateArrival::class);

        // русский хелпер подписей enum-ов для всех шаблонов
        View::share('crm_label', static fn (?string $group = null, ?string $value = null) => $group === null ? '' : EnumLabels::label($group, $value));

        // Blade-гейт @can('checkBoxPermission', 'slug.permission'):
        // UI-кнопки гейтятся той же матрицей прав, что и HTTP-middleware.
        // Без before-замыкания все кнопки управления пропадали у всех, включая админа.
        Gate::before(function ($user, $ability, array $parameters) use ($permissions) {
            if ($ability !== 'checkBoxPermission') {
                return null; // прочие способы — стандартные правила Gate
            }

            $permission = $parameters[0] ?? null;

            return is_string($permission) && $permission !== ''
                ? $permissions->check($user, $permission)
                : false;
        });
    }
}
