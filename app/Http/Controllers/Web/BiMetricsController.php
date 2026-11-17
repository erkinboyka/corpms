<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Bi\Services\BiService;
use App\Http\Controllers\Controller;
use App\Support\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Срезы витрины BI (Этап E6): итоги, средние и динамика по каждой метрике за период.
 */
class BiMetricsController extends Controller
{
    public function __construct(
        protected BiService $bi,
        protected PermissionService $permissions,
    ) {}

    /** Таблица срезов по всем метрикам за месяц. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = now()->format('Y-m');
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
        $from = $start->startOfMonth()->toDateString();
        $to = $start->endOfMonth()->toDateString();

        $rows = [];

        foreach ($this->bi->catalog() as $code => $meta) {
            $series = $this->bi->series($companyId, $code, $from, $to);

            $rows[] = [
                'code' => $code,
                'meta' => $meta,
                'summary' => $this->bi->summary($companyId, $code, $from, $to),
                'points' => $this->bi->sparklinePoints($series),
                'series' => $series,
            ];
        }

        return view('bi.metrics', [
            'rows' => $rows,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'totals' => $this->bi->totals($companyId, $from, $to),
            'canManage' => $this->permissions->check($request->user(), 'bi.manage'),
        ]);
    }
}
