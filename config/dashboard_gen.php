<?php

declare(strict_types=1);

/*
 * Генератор BI-дашбордов (адаптация пайплайна Datavue под corpms).
 *
 * tables — белый список таблиц, доступных ИИ для SQL-виджетов. Это граница
 * безопасности: колонки, авторизационные, переписка, документы и PII в список
 * не входят. Если у таблицы есть company_id, запрос обязан фильтровать по нему
 * плейсхолдером :company_id — значение подставляет платформа, модель его не
 * знаходит; без фильтра валидатор отклоняет запрос и просит исправить.
 */
return [

    'tables' => [
        // витрина и финансы
        'bi_metrics',
        'fin_accounts',
        'fin_categories',
        'fin_transactions',
        'fin_invoices',
        'fin_payments',
        'fin_budgets',

        // бухгалтерия
        'acc_documents',
        'acc_entries',
        'acc_entry_lines',

        // закупки и склад
        'purchase_orders',
        'purchase_order_items',
        'suppliers',
        'products',
        'stock_movements',
        'warehouses',

        // CRM
        'crm_clients',
        'crm_contacts',
        'crm_deals',
        'crm_leads',
        'crm_activities',

        // задачи и проекты
        'tasks',
        'task_assignments',
        'task_time_logs',
        'projects',
        'project_milestones',
        'project_members',

        // кадры (без PII и окладов)
        'departments',
        'positions',
        'holidays',

        // payroll на уровне периодов/оплат (без построчных сумм сотрудников)
        'payroll_periods',
        'payroll_payments',
    ],

    // Потолок виджетов в одном дашборде.
    'max_widgets' => 12,

    // Сколько попыток генерации/ремонта спецификации на один виджет.
    'max_attempts' => 3,

    // Сколько уникальных значений берётся в sample_values для колонки.
    'sample_limit' => 5,

    // Сколько строк исходника просматривается при подборе значений.
    'sample_source_rows' => 1000,

    // Потолок выборок sample_values на один дашборд (защита от шторма запросов).
    'max_sample_queries' => 80,
];
