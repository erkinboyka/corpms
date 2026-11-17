<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Accounting\Services\AccountingService;
use App\Http\Controllers\Controller;
use App\Support\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Отчёты бухгалтерии (Этап E4): оборотка, ОПиУ, упрощённый баланс.
 */
class AccountingReportController extends Controller
{
    public function __construct(
        protected AccountingService $accounting,
        protected PermissionService $permissions,
    ) {}

    /** Отчёты за период. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        $from = $month.'-01';
        $to = CarbonImmutable::createFromFormat('Y-m-d', $from)->endOfMonth()->toDateString();

        $trialBalance = $this->accounting->trialBalance($companyId, $from, $to);

        return view('accounting.reports.index', [
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'trialBalance' => $trialBalance,
            'trialTotals' => [
                'debit' => round($trialBalance->sum(static fn ($row): float => (float) $row->debit_turnover), 2),
                'credit' => round($trialBalance->sum(static fn ($row): float => (float) $row->credit_turnover), 2),
                'balance' => round($trialBalance->sum(static fn ($row): float => (float) $row->balance), 2),
            ],
            'profitAndLoss' => $this->accounting->profitAndLoss($companyId, $from, $to),
            'balanceSheet' => $this->accounting->balanceSheet($companyId, $to),
            'canManage' => $this->permissions->check($request->user(), 'accounting.manage'),
        ]);
    }
}
