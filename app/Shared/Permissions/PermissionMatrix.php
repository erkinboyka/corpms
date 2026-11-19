<?php

declare(strict_types=1);

namespace App\Shared\Permissions;

/**
 * Центральная матрица permission'ов системы.
 * slug => [name, group]
 *
 * Все проверки доступа в системе идут через PermissionService,
 * который использует этот список + role_permissions + overrides.
 */
final class PermissionMatrix
{
    public const GROUPS = [
        'organization' => 'Организация',
        'hr' => 'Персонал',
        'attendance' => 'Посещаемость',
        'biometry' => 'Биометрия',
        'tasks' => 'Задачи',
        'documents' => 'Документы',
        'workflow' => 'Workflow',
        'signature' => 'Подпись',
        'chat' => 'Чат',
        'analytics' => 'Аналитика',
        'reports' => 'Отчёты',
        'payroll' => 'Зарплата',
        'inventory' => 'Склад и закупки',
        'crm' => 'CRM',
        'finance' => 'Финансы',
        'accounting' => 'Бухгалтерия',
        'projects' => 'Проекты',
        'bi' => 'BI',
        'system' => 'Система',
    ];

    public const PERMISSIONS = [
        // --- Организация ---
        'companies.view' => ['Просмотр компаний', 'organization'],
        'companies.manage' => ['Управление компаниями', 'organization'],
        'departments.view' => ['Просмотр отделов', 'organization'],
        'departments.manage' => ['Управление отделами', 'organization'],
        'positions.view' => ['Просмотр должностей', 'organization'],
        'positions.manage' => ['Управление должностями', 'organization'],
        'org_chart.view' => ['Оргструктура', 'organization'],
        'structure.manage' => ['Изменение оргструктуры', 'organization'],

        // --- Персонал ---
        'employees.view' => ['Просмотр сотрудников', 'hr'],
        'employees.view_props' => ['Просмотр профилей', 'hr'],
        'employees.manage' => ['Управление сотрудниками', 'hr'],
        'employees.roles' => ['Назначение ролей', 'hr'],
        'schedules.view' => ['Просмотр графиков', 'hr'],
        'schedules.manage' => ['Управление графиками', 'hr'],
        'holidays.manage' => ['Управление праздниками', 'hr'],
        'leaves.view' => ['Просмотр заявок на отсутствие', 'hr'],
        'leaves.request' => ['Создание заявок на отсутствие', 'hr'],
        'leaves.approve' => ['Согласование отсутствий', 'hr'],

        // --- Посещаемость ---
        'attendance.view' => ['Просмотр посещаемости', 'attendance'],
        'attendance.live.view' => ['Live-посещаемость', 'attendance'],
        'attendance.timesheet.view' => ['Табель', 'attendance'],
        'attendance.timesheet.export' => ['Экспорт табеля', 'attendance'],
        'attendance.anomalies.view' => ['Аномалии посещаемости', 'attendance'],
        'attendance.anomalies.manage' => ['Обработка аномалий', 'attendance'],
        'attendance.correct' => ['Корректировка посещаемости', 'attendance'],
        'attendance.corrections.approve' => ['Согласование корректировок', 'attendance'],
        'attendance.dashboard.view' => ['Дашборд посещаемости', 'attendance'],

        // --- Биометрия ---
        'biometry.dashboard.view' => ['Статистика биометрии', 'biometry'],
        'biometry.devices.view' => ['Просмотр устройств', 'biometry'],
        'biometry.devices.manage' => ['Управление устройствами', 'biometry'],
        'biometry.enroll' => ['Регистрация биопрофиля', 'biometry'],
        'biometry.profiles.manage' => ['Управление биопрофилями', 'biometry'],
        'biometry.events.view' => ['Просмотр биособытий', 'biometry'],
        'biometry.privacy.manage' => ['Политика биометрии', 'biometry'],
        'biometry.rfid.view' => ['Просмотр RFID-карт и событий', 'biometry'],
        'biometry.rfid.manage' => ['Выдача и отзыв RFID-карт', 'biometry'],

        // --- Задачи ---
        'tasks.view' => ['Просмотр задач', 'tasks'],
        'tasks.create' => ['Создание задач', 'tasks'],
        'tasks.assign' => ['Назначение задач', 'tasks'],
        'tasks.assign_department' => ['Назначение задач отделу', 'tasks'],
        'tasks.manage' => ['Управление задачами', 'tasks'],
        'tasks.comment' => ['Комментарии к задачам', 'tasks'],
        'tasks.delegate' => ['Делегирование задач', 'tasks'],
        'tasks.complete' => ['Завершение задач', 'tasks'],
        'tasks.reassign' => ['Переназначение задач', 'tasks'],

        // --- Документы ---
        'documents.view' => ['Просмотр документов', 'documents'],
        'documents.create' => ['Создание документов', 'documents'],
        'documents.edit' => ['Редактирование документов', 'documents'],
        'documents.submit' => ['Отправка на согласование', 'documents'],
        'documents.approve' => ['Согласование', 'documents'],
        'documents.reject' => ['Отказ в согласовании', 'documents'],
        'documents.archive' => ['Архив', 'documents'],
        'documents.templates.view' => ['Просмотр шаблонов', 'documents'],
        'documents.templates.manage' => ['Управление шаблонами', 'documents'],
        'documents.categories.manage' => ['Управление категориями', 'documents'],

        // --- Workflow ---
        'workflows.view' => ['Просмотр workflow', 'workflow'],
        'workflows.manage' => ['Конструктор workflow', 'workflow'],

        // --- Подпись ---
        'signatures.view' => ['Просмотр подписей', 'signature'],
        'signatures.request' => ['Создание запросов на подпись', 'signature'],
        'signatures.sign' => ['Подписание', 'signature'],
        'signatures.revoke' => ['Отзыв подписи', 'signature'],
        'signatures.verify' => ['Проверка подписи', 'signature'],

        // --- BM Chat ---
        'chat.view' => ['Просмотр чатов', 'chat'],
        'chat.send' => ['Отправка сообщений', 'chat'],
        'chat.manage' => ['Управление диалогами и модерация', 'chat'],
        'chat.groups.create' => ['Создание групп', 'chat'],
        'chat.channels.create' => ['Создание каналов компании', 'chat'],

        // --- Аналитика ---
        'analytics.view' => ['Просмотр аналитики', 'analytics'],
        'analytics.ai.use' => ['AI-аномалитики', 'analytics'],

        // --- Отчёты ---
        'reports.view' => ['Просмотр отчётов', 'reports'],
        'reports.export' => ['Экспорт отчётов', 'reports'],

        // --- Зарплата ---
        'payroll.view' => ['Просмотр зарплатных ведомостей', 'payroll'],
        'payroll.manage' => ['Расчёт зарплатных ведомостей', 'payroll'],
        'payroll.approve' => ['Согласование и выплата ведомостей', 'payroll'],

        // --- Склад и закупки (Этап E1: ERP) ---
        'inventory.view' => ['Просмотр склада и остатков', 'inventory'],
        'inventory.manage' => ['Управление справочниками склада', 'inventory'],
        'inventory.move' => ['Складские операции (приход/расход)', 'inventory'],
        'purchases.view' => ['Просмотр закупочных заказов', 'inventory'],
        'purchases.manage' => ['Управление закупочными заказами', 'inventory'],

        // --- CRM (Этап E2) ---
        'crm.view' => ['Просмотр клиентов, лидов и сделок', 'crm'],
        'crm.manage' => ['Управление клиентами, лидами и активностями', 'crm'],
        'crm.deals.manage' => ['Управление сделками и конверсия лидов', 'crm'],

        // --- Финансы (Этап E3) ---
        'finance.view' => ['Просмотр кассы, счетов и бюджетов', 'finance'],
        'finance.manage' => ['Управление счетами, операциями и бюджетами', 'finance'],
        'finance.invoices.manage' => ['Выставление счетов и приём платежей', 'finance'],

        // --- Бухгалтерия (Этап E4) ---
        'accounting.view' => ['Просмотр плана счетов, проводок и отчётов', 'accounting'],
        'accounting.manage' => ['Управление планом счетов, проводками и документами', 'accounting'],

        // --- Проекты (Этап E5) ---
        'projects.view' => ['Просмотр проектов, бюджета и задач', 'projects'],
        'projects.manage' => ['Управление проектами, участниками, вехами и задачами', 'projects'],

        // --- BI (Этап E6) ---
        'bi.view' => ['Просмотр витрины, дашбордов и прогнозов', 'bi'],
        'bi.manage' => ['Сборка витрины и настройка дашбордов', 'bi'],

        // --- Система ---
        'audit.view' => ['История действий', 'system'],
        'settings.view' => ['Настройки — просмотр', 'system'],
        'settings.manage' => ['Настройки — изменение', 'system'],
        'users.manage' => ['Управление пользователями', 'system'],
        'integrations.manage' => ['Интеграции и API', 'system'],
        'search.global' => ['Глобальный поиск', 'system'],
    ];

    public static function all(): array
    {
        return self::PERMISSIONS;
    }

    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::PERMISSIONS[$slug]);
    }

    /** These resources have company ownership and no implemented own/department boundary. */
    public static function requiresCompanyScope(string $slug): bool
    {
        return in_array($slug, [
            'inventory.view', 'inventory.manage', 'inventory.move', 'purchases.view', 'purchases.manage',
            'crm.view', 'crm.manage', 'crm.deals.manage', 'finance.view', 'finance.manage', 'finance.invoices.manage',
            'accounting.view', 'accounting.manage', 'projects.view', 'projects.manage', 'bi.view', 'bi.manage',
            'users.manage', 'integrations.manage', 'positions.manage', 'schedules.manage', 'holidays.manage',
            'documents.templates.manage', 'documents.categories.manage', 'workflows.manage',
        ], true);
    }
}
