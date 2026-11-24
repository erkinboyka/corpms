<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Навигация BM Systems с учётом permissions.
 *
 * Формат элемента ITEMS:
 *   ['_', 'Название']                                       — заголовок секции
 *   ['route', 'title', 'icon', 'permission|null']           — пункт меню
 *   ['module', 'title', 'icon', 'permission|null', [ ... ]] — модуль со вложенными пунктами
 *
 * Модуль показывается, если виден хотя бы один его пункт; пункты фильтруются
 * индивидуально. Активный модуль раскрывается на клиенте (data-nav-group).
 */
class Sidebar
{
    private const PARENT_ROUTES = [
        'accounting.entries.show' => 'accounting.entries.index',
        'bi.dashboards.show' => 'bi.index',
        'crm.clients.show' => 'crm.clients.index',
        'documents.show' => 'documents.index',
        'employees.show' => 'employees.index',
        'employees.subordinates' => 'employees.index',
        'onboarding.flows.show' => 'onboarding.flows.index',
        'finance.invoices.show' => 'finance.invoices.index',
        'lms.courses.show' => 'lms.index',
        'lms.lessons.show' => 'lms.index',
        'lms.whiteboards.show' => 'lms.index',
        'lms.meetings.show' => 'lms.index',
        'payroll.show' => 'payroll.index',
        'projects.show' => 'projects.index',
        'purchases.show' => 'purchases.index',
        'reports.attendance' => 'reports.index',
        'reports.hr' => 'reports.index',
        'reports.tasks' => 'reports.index',
        'tasks.show' => 'tasks.index',
    ];

    public static function activeRoute(?string $name): ?string
    {
        return self::PARENT_ROUTES[$name] ?? $name;
    }

    /** @var array<int, array{0:string,1:string,2?:string,3?:?string,4?:array}> */
    protected const ITEMS = [
        ['_', 'Обзор', 'layout-dashboard'],
        ['dashboard', 'Дашборд', 'layout-dashboard', 'attendance.dashboard.view'],
        ['notifications.index', 'Уведомления', 'bell', 'attendance.view'],
        ['chat.index', 'Чат', 'message-circle', 'chat.view'],

        ['_', 'Работа', 'briefcase'],
        ['module', 'Задачи', 'list-check', 'tasks.view', [
            ['tasks.index', 'Все задачи', 'list', null],
            ['tasks.mine', 'Мои задачи', 'user-check', null],
            ['tasks.overdue', 'Просроченные', 'alarm', null],
        ]],
        ['module', 'Обучение', 'school', 'lms.view', [
            ['lms.index', 'Курсы и доски', 'book', null],
            ['lms.assignments.index', 'Практика', 'list-check', null],
            ['lms.pulse', 'Пульс Bumi', 'pulse', null],
        ]],
        ['projects.index', 'Проекты', 'folders', 'projects.view'],
        ['module', 'Графики и отпуска', 'calendar-time', null, [
            ['schedules.index', 'Графики работы', 'clock-bolt', 'schedules.view'],
            ['schedules.holidays', 'Праздники', 'calendar-event', 'schedules.view'],
            ['schedules.leaves', 'Отпуска и заявки', 'calendar-off', 'leaves.view'],
            ['schedules.mine', 'Мой график', 'alarm', 'attendance.view'],
        ]],

        ['_', 'Люди', 'users'],
        ['employees.index', 'Сотрудники', 'users', 'employees.view'],
        ['onboarding.flows.index', 'Онбординг найма', 'user-plus', 'onboarding.view'],
        ['module', 'Организация', 'building', null, [
            ['departments.index', 'Отделы', 'sitemap', 'departments.view'],
            ['positions.index', 'Должности', 'briefcase', 'positions.view'],
            ['orgchart.index', 'Оргструктура', 'binary-tree', 'org_chart.view'],
            ['companies.index', 'Компании', 'building-castle', 'companies.view'],
        ]],
        ['module', 'Посещаемость', 'clock', null, [
            ['attendance.index', 'Сегодня', 'clock-hour-4', 'attendance.view'],
            ['attendance.live', 'В реальном времени', 'broadcast', 'attendance.live.view'],
            ['attendance.timesheet', 'Табель', 'calendar-time', 'attendance.timesheet.view'],
            ['attendance.anomalies', 'Аномалии', 'alert-triangle', 'attendance.anomalies.view'],
            ['attendance.corrections', 'Корректировки', 'notebook', 'attendance.correct'],
        ]],
        ['module', 'Зарплата', 'wallet', null, [
            ['payroll.index', 'Ведомости', 'report-money', 'payroll.view'],
            ['payroll.salaries', 'Оклады и компоненты', 'coins', 'payroll.view'],
        ]],

        ['_', 'Клиенты', 'address-book'],
        ['module', 'CRM', 'chart-funnel', null, [
            ['crm.clients.index', 'Клиенты', 'address-book', 'crm.view'],
            ['crm.leads.index', 'Лиды', 'user-search', 'crm.view'],
            ['crm.deals.index', 'Сделки', 'transaction-euro', 'crm.view'],
            ['crm.activities.index', 'Активности', 'calendar-event', 'crm.view'],
        ]],

        ['_', 'Склад и закупки', 'package'],
        ['module', 'Склад', 'package', null, [
            ['inventory.index', 'Остатки', 'packages', 'inventory.view'],
            ['inventory.products', 'Товары', 'box', 'inventory.view'],
            ['inventory.warehouses', 'Склады', 'building-warehouse', 'inventory.view'],
            ['inventory.suppliers', 'Поставщики', 'truck-delivery', 'inventory.view'],
            ['inventory.movements', 'Движения', 'arrows-exchange', 'inventory.view'],
        ]],
        ['purchases.index', 'Закупки', 'shopping-cart', 'purchases.view'],

        ['_', 'Финансы', 'wallet'],
        ['module', 'Денежные операции', 'cash-banknote', null, [
            ['finance.accounts.index', 'Касса и счета', 'wallet', 'finance.view'],
            ['finance.invoices.index', 'Счета и акты', 'file-invoice', 'finance.view'],
            ['finance.transactions.index', 'Доходы и расходы', 'receipt', 'finance.view'],
            ['finance.budgets.index', 'Бюджеты', 'target', 'finance.view'],
            ['finance.categories.index', 'Статьи', 'category', 'finance.view'],
        ]],
        ['module', 'Бухгалтерия', 'book-2', null, [
            ['accounting.chart.index', 'План счетов', 'list-numbers', 'accounting.view'],
            ['accounting.entries.index', 'Проводки', 'arrows-right-left', 'accounting.view'],
            ['accounting.documents.index', 'Первичные документы', 'files', 'accounting.view'],
            ['accounting.reports.index', 'Бухгалтерские отчёты', 'report-analytics', 'accounting.view'],
        ]],

        ['_', 'Документы', 'files'],
        ['module', 'Документооборот', 'files', null, [
            ['documents.index', 'Все документы', 'folder', 'documents.view'],
            ['documents.mine', 'Мои документы', 'file', 'documents.view'],
            ['documents.templates', 'Шаблоны', 'template', 'documents.templates.view'],
            ['workflows.index', 'Маршруты согласования', 'arrows-split', 'workflows.view'],
        ]],

        ['_', 'Аналитика', 'chart-bar'],
        ['analytics.index', 'Аналитика', 'chart-bar', 'analytics.view'],
        ['reports.index', 'Отчёты', 'report', 'reports.view'],
        ['module', 'BI-витрина', 'chart-histogram', null, [
            ['bi.index', 'BI-дашборды', 'chart-dots', 'bi.view'],
            ['bi.metrics.index', 'Срезы витрины', 'chart-line', 'bi.view'],
        ]],
        ['ai.index', 'Bumi', 'sparkles', 'analytics.ai.use'],
        ['audit.index', 'Журнал действий', 'history', 'audit.view@company'],

        ['_', 'Администрирование', 'adjustments'],
        ['users.index', 'Учётные записи', 'user-cog', 'users.manage'],
        ['integrations.index', 'Интеграции', 'plug', 'integrations.manage'],
        ['imports.index', 'Импорт данных', 'upload', 'employees.manage@company'],
        ['backups.index', 'Резервные копии', 'database-export', 'backups.manage'],
        ['certificates.index', 'Доверенные сертификаты', 'certificate', 'settings.view@company'],
        ['module', 'Биометрия', 'fingerprint', null, [
            ['biometrics.index', 'Устройства', 'device-fingerprint', 'biometry.devices.view'],
            ['biometrics.events', 'События', 'activity', 'biometry.events.view'],
            ['biometrics.privacy', 'Приватность', 'lock', 'biometry.privacy.manage'],
        ]],
        ['rfid.index', 'RFID-карты', 'credit-card', 'biometry.rfid.view'],
        ['settings.index', 'Настройки', 'settings', 'settings.view@company'],
    ];

    /**
     * Готовый список для рендера: секции без единого видимого пункта выкидываются,
     * модули — если ни один их пункт не виден.
     *
     * @return array<int, array{type:string, label?:string, route?:string, title?:string, icon?:string, children?:array}>
     */
    public static function build(User $user): array
    {
        $permissions = app(PermissionService::class);
        $list = [];
        $sectionLabel = null;
        $sectionIcon = 'folder';
        $sectionHasVisible = false;
        $sectionStart = null;

        $flushSection = static function () use (&$list, &$sectionLabel, &$sectionIcon, &$sectionHasVisible, &$sectionStart): void {
            if ($sectionLabel !== null) {
                if ($sectionHasVisible && $sectionStart !== null) {
                    array_splice($list, $sectionStart, 0, [
                        ['type' => 'section', 'label' => $sectionLabel, 'icon' => $sectionIcon],
                    ]);
                }
                $sectionLabel = null;
                $sectionIcon = 'folder';
                $sectionHasVisible = false;
                $sectionStart = null;
            }
        };

        foreach (self::ITEMS as $item) {
            if ($item[0] === '_') {
                $flushSection();
                $sectionLabel = __($item[1]);
                $sectionIcon = $item[2] ?? 'folder';
                $sectionStart = count($list);

                continue;
            }

            if ($item[0] === 'module') {
                [, $title, $icon, $permission, $children] = $item;

                $visibleChildren = [];
                foreach ($children as [$route, $childTitle, $childIcon, $childPermission]) {
                    if ($childPermission !== null && ! $permissions->check($user, $childPermission)) {
                        continue;
                    }
                    $visibleChildren[] = ['type' => 'item', 'route' => $route, 'title' => __($childTitle), 'icon' => $childIcon];
                }

                if ($visibleChildren === [] || ($permission !== null && ! $permissions->check($user, $permission))) {
                    continue;
                }

                $list[] = ['type' => 'module', 'title' => __($title), 'icon' => $icon, 'children' => $visibleChildren];
                $sectionHasVisible = true;

                continue;
            }

            [$route, $title, $icon, $permission] = $item;

            if ($permission !== null && ! $permissions->check($user, $permission)) {
                continue;
            }

            $list[] = ['type' => 'item', 'route' => $route, 'title' => __($title), 'icon' => $icon];
            $sectionHasVisible = true;
        }

        $flushSection();

        return $list;
    }

    /**
     * Все маршруты, видимые пользователю, — верхние пункты и вложенные
     * пункты модулей (формат build() со вложенными children).
     *
     * @return list<string>
     */
    public static function routes(User $user): array
    {
        $routes = [];

        foreach (self::build($user) as $item) {
            if (($item['type'] ?? null) === 'module') {
                foreach ($item['children'] as $child) {
                    $routes[] = $child['route'];
                }

                continue;
            }

            if (isset($item['route'])) {
                $routes[] = $item['route'];
            }
        }

        return $routes;
    }

    /**
     * Хлебные крошки для маршрута: ['Секция', 'Модуль|null', 'Страница'].
     * Для маршрутов вне сайдбара (детальные страницы) — null: такие страницы
     * задают крошки сами через partials/breadcrumbs.
     *
     * @return array{0:string,1:?string,2:string}|null
     */
    public static function breadcrumbsFor(string $routeName): ?array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = [];
            $section = null;
            $module = null;

            foreach (self::ITEMS as $item) {
                if ($item[0] === '_') {
                    $section = __($item[1]);
                    $module = null;

                    continue;
                }

                if ($item[0] === 'module') {
                    $module = __($item[1]);

                    foreach ($item[4] as [$childRoute, $childTitle]) {
                        $cache[$childRoute] = [$section, $module, __($childTitle)];
                    }

                    continue;
                }

                $cache[$item[0]] = [$section, null, __($item[1])];
            }
        }

        return $cache[$routeName] ?? null;
    }
}
