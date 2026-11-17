<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Навигация с учётом permissions (п.58 ТЗ).
 *
 * Спецификация элемента:
 *   ['_', 'Дашборд']                        — секция (заголовок)
 *   ['route', 'title', 'icon', 'permission|null']
 */
class Sidebar
{
    /** @var array<int, array{0:string,1:string,2?:string,3?:?string}> */
    protected const ITEMS = [
        ['_', 'Главная'],
        ['dashboard', 'Дашборд', 'layout-dashboard', 'attendance.dashboard.view'],

        ['_', 'Организация'],
        ['companies.index', 'Компании', 'building', 'companies.view'],
        ['departments.index', 'Отделы', 'sitemap', 'departments.view'],
        ['positions.index', 'Должности', 'briefcase', 'positions.view'],
        ['employees.index', 'Сотрудники', 'users', 'employees.view'],
        ['orgchart.index', 'Оргструктура', 'binary-tree', 'org_chart.view'],

        ['_', 'Графики и отпуска'],
        ['schedules.index', 'Графики работы', 'clock-bolt', 'schedules.view'],
        ['schedules.holidays', 'Праздники', 'calendar-event', 'schedules.view'],
        ['schedules.leaves', 'Отпуска и заявки', 'calendar-off', 'leaves.view'],
        ['schedules.mine', 'Мой график', 'alarm', 'attendance.view'],

        ['_', 'Посещаемость'],
        ['attendance.index', 'Сегодня', 'clock', 'attendance.view'],
        ['attendance.live', 'Live', 'broadcast', 'attendance.live.view'],
        ['attendance.timesheet', 'Табель', 'calendar-time', 'attendance.timesheet.view'],
        ['attendance.anomalies', 'Аномалии', 'alert-triangle', 'attendance.anomalies.view'],
        ['attendance.corrections', 'Корректировки', 'notebook', 'attendance.correct'],
        ['biometrics.index', 'Биометрия', 'fingerprint', 'biometry.devices.view'],
        ['biometrics.events', 'События биометрии', 'activity', 'biometry.events.view'],
        ['biometrics.privacy', 'Приватность биометрии', 'lock', 'biometry.privacy.manage'],
        ['rfid.index', 'RFID-карты', 'credit-card', 'biometry.rfid.view'],

        ['_', 'Зарплата'],
        ['payroll.index', 'Ведомости', 'wallet', 'payroll.view'],
        ['payroll.salaries', 'Оклады и компоненты', 'coins', 'payroll.view'],

        ['_', 'Задачи'],
        ['tasks.index', 'Все задачи', 'list-check', 'tasks.view'],
        ['tasks.mine', 'Мои задачи', 'user-check', 'tasks.view'],
        ['tasks.overdue', 'Просроченные', 'alarm', 'tasks.view'],

        ['_', 'Документы'],
        ['documents.index', 'Все документы', 'files', 'documents.view'],
        ['documents.mine', 'Мои документы', 'folder', 'documents.view'],
        ['documents.templates', 'Шаблоны', 'template', 'documents.templates.view'],
        ['workflows.index', 'Маршруты согласования', 'arrows-split', 'workflows.view'],

        ['_', 'Аналитика'],
        ['analytics.index', 'Аналитика', 'chart-bar', 'analytics.view'],
        ['reports.index', 'Отчёты', 'report', 'reports.view'],
        ['ai.index', 'AI-ассистент', 'sparkles', 'analytics.ai.use'],

        ['_', 'Система'],
        ['audit.index', 'Audit Log', 'history', 'audit.view'],
        ['users.index', 'Учётные записи', 'user-cog', 'users.manage'],
        ['integrations.index', 'Интеграции', 'plug', 'integrations.manage'],
        ['settings.index', 'Настройки', 'settings', 'settings.view'],
    ];

    /**
     * Готовый список для рендера: секции без единого видимого пункта выкидываются.
     *
     * @return array<int, array{type:string, label?:?string, route?:string, title?:string, icon?:string}>
     */
    public static function build(User $user): array
    {
        $permissions = app(PermissionService::class);
        $list = [];
        $sectionLabel = null;

        foreach (self::ITEMS as $item) {
            // ['_', 'Заголовок секции']
            if ($item[0] === '_') {
                $sectionLabel = $item[1];

                continue;
            }

            [$route, $title, $icon, $permission] = $item;

            $visible = $permission === null || $permissions->check($user, $permission);
            if ($visible === false) {
                continue;
            }

            if ($sectionLabel !== null) {
                $list[] = ['type' => 'section', 'label' => $sectionLabel];
                $sectionLabel = null;
            }

            $list[] = ['type' => 'item', 'route' => $route, 'title' => $title, 'icon' => $icon];
        }

        return $list;
    }
}
