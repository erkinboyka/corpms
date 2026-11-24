<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Bi\Services\BiService;
use App\Domains\Finance\Services\FinanceService;
use App\Models\User;
use App\Support\PermissionService;

/**
 * BI-01: набор метрик дашборда определяется правами, а не ролью «на глаз».
 *
 * Каждая группа строится только если у пользователя есть соответствующее
 * право; данные выборки ограничены его компанией и scope права.
 * Денежные суммы из разных валют не складываются: FinanceService отдаёт null,
 * который отображается как «—» без выдуманного курса.
 */
class DashboardMetricsService
{
    private const BI_CODES = [
        'finance_income', 'finance_expense', 'deals_won_amount',
        'invoices_paid', 'tasks_completed', 'headcount',
    ];

    public function __construct(
        protected PermissionService $permissions,
        protected AnalyticsService $analytics,
        protected FinanceService $finance,
        protected BiService $bi,
    ) {}

    /**
     * @return array<int, array{key: string, title: string, route: ?string, items: array<int, array{label: string, value: string}>, empty: bool}>
     */
    public function for(User $user): array
    {
        $employee = $this->permissions->employee($user);
        if ($employee === null) {
            return [];
        }

        $companyId = (int) $employee->company_id;
        $groups = [];

        if ($this->permissions->check($user, 'finance.view')) {
            $metrics = $this->finance->metrics($companyId, now()->format('Y-m'));
            $money = [
                ['label' => __('Баланс'), 'raw' => $metrics['balance']],
                ['label' => __('Доходы'), 'raw' => $metrics['income']],
                ['label' => __('Расходы'), 'raw' => $metrics['expense']],
                ['label' => __('Прибыль'), 'raw' => $metrics['profit']],
                ['label' => __('Неоплаченные счета'), 'raw' => $metrics['unpaid']],
                ['label' => __('Просроченные счета'), 'raw' => $metrics['invoices_overdue']],
            ];
            $groups[] = [
                'key' => 'finance',
                'title' => __('Финансовые показатели'),
                'route' => route('finance.accounts.index'),
                'items' => array_map(
                    fn (array $item): array => ['label' => $item['label'], 'value' => $this->format($item['raw'], 'money')],
                    $money,
                ),
                'empty' => $this->isEmpty(array_column($money, 'raw')),
            ];
        }

        if ($this->permissions->check($user, 'employees.view')) {
            $access = $this->analytics->accessibleEmployees($user, 'employees.view');
            $headcount = $this->analytics->headcount($access['employees']);
            $hr = [
                ['label' => __('Активных'), 'raw' => $headcount['active']],
                ['label' => __('Всего в доступе'), 'raw' => $headcount['total']],
            ];
            $groups[] = [
                'key' => 'hr',
                'title' => __('Кадровые показатели'),
                'route' => route('employees.index'),
                'items' => array_map(
                    fn (array $item): array => ['label' => $item['label'], 'value' => $this->format($item['raw'], 'count')],
                    $hr,
                ),
                'empty' => $this->isEmpty(array_column($hr, 'raw')),
            ];
        }

        if ($this->permissions->check($user, 'bi.view')) {
            $totals = $this->bi->totals($companyId, now()->startOfMonth()->toDateString(), now()->toDateString());
            $bi = [];
            foreach (self::BI_CODES as $code) {
                $bi[] = [
                    'label' => BiService::METRICS[$code]['label'],
                    'raw' => $totals[$code] ?? 0,
                    'unit' => BiService::METRICS[$code]['unit'],
                ];
            }
            $groups[] = [
                'key' => 'bi',
                'title' => __('BI-витрина'),
                'route' => route('bi.index'),
                'items' => array_map(
                    fn (array $item): array => ['label' => $item['label'], 'value' => $this->format($item['raw'], $item['unit'])],
                    $bi,
                ),
                'empty' => $this->isEmpty(array_column($bi, 'raw')),
            ];
        }

        return $groups;
    }

    /** Не складываем разные валюты: null выводится как «—». */
    private function format(mixed $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }

        return $unit === 'money'
            ? number_format((float) $value, 2, ',', ' ')
            : number_format((float) $value, 0, ',', ' ');
    }

    /** @param array<int, mixed> $values */
    private function isEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && (float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }
}
