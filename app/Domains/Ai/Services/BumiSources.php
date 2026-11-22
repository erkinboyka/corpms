<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\Employee;
use App\Support\PermissionService;

final class BumiSources
{
    private const REGISTERS = [
        'tasks' => ['Задачи и назначения', 'tasks.view', 'tasks.index'],
        'attendance' => ['Журнал посещаемости', 'attendance.view', 'attendance.index'],
        'headcount' => ['Справочник сотрудников', 'employees.view', 'employees.index'],
        'leaves' => ['Заявки на отсутствие', 'leaves.view', 'schedules.leaves'],
        'message' => ['Числа из вашего сообщения', null, null],
    ];

    public static function toolItems(string $name, array $result): array
    {
        $register = match ($name) {
            'finance_summary' => 'Финансовые операции', 'crm_pipeline_summary' => 'Сделки CRM',
            'projects_summary' => 'Реестр проектов', 'inventory_stock_summary' => 'Остатки склада',
            'accounting_trial_balance' => 'Оборотно-сальдовая ведомость', 'bi_metrics_summary' => 'Показатели BI',
            'attendance_summary' => 'Журнал посещаемости', 'task_stats' => 'Аналитика задач',
            'search_documents' => 'Найденные документы', 'search_tasks' => 'Найденные задачи',
            default => null,
        };
        $items = [];
        // конкретные записи важнее общего реестра: они первыми попадают в UI
        foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $row) {
            if (is_array($row) && isset($row['url'])) {
                $items[] = ['label' => $row['title'] ?? $row['name'] ?? null, 'url' => parse_url((string) $row['url'], PHP_URL_PATH)];
            }
        }
        if ($register !== null) {
            $url = $result['url'] ?? match ($name) {
                'attendance_summary' => route('attendance.index'), 'task_stats' => route('analytics.index'), default => null,
            };
            $items[] = ['label' => trim($register.' '.($result['month'] ?? $result['date'] ?? '')), 'url' => $url ? parse_url($url, PHP_URL_PATH).(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '') : null];
        }

        return $items;
    }

    public static function snapshot(Employee $me, array $registers, array $facts, ?string $period = null): array
    {
        $items = [];
        foreach ($registers as $register) {
            [$label, $permission, $route] = self::REGISTERS[$register];
            $url = null;
            if ($permission && $me->user && app(PermissionService::class)->check($me->user, $permission)) {
                $url = route($route, absolute: false);
            }
            $items[] = ['label' => $label, 'url' => $url];
        }
        $scope = match ($facts['scope'] ?? 'own') {
            'company' => 'ваша компания', 'department' => 'ваше подразделение', default => 'личные данные',
        };
        $stamp = now()->format('d.m.Y H:i');
        $context = $registers === ['message'] ? 'Данные пользователя' : 'Область выборки — '.$scope;

        return ['type' => 'sources', 'items' => $items, 'context' => implode(' · ', array_filter([$period, $context, $stamp]))];
    }
}
