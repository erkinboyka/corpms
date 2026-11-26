<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\DTO\BumiRoleContext;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LmsCourse;
use App\Models\LmsLesson;
use App\Shared\Enums\BumiRole;
use Illuminate\Http\Request;
use Throwable;

/**
 * Роль Bumi выводится из текущего маршрута, а не из клиентского флага.
 * Один и тот же Мини-ассистент на уроке становится репетитором, в документах —
 * клерком, в финансах — бухгалтером, в BI/аналитике — аналитиком.
 */
class BumiRoleService
{
    public function current(?Request $request = null): BumiRoleContext
    {
        $request ??= request();
        $route = (string) ($request->route()?->getName() ?? '');
        $role = $this->roleForRoute($route);
        [$context, $scenario] = $this->pageContext($request, $route, $role);

        return new BumiRoleContext($role, $route, $context, $scenario, $role->tools());
    }

    protected function roleForRoute(string $route): BumiRole
    {
        return match (true) {
            str_starts_with($route, 'lms.') => BumiRole::Tutor,
            str_starts_with($route, 'documents.') => BumiRole::Clerk,
            str_starts_with($route, 'finance.'),
            str_starts_with($route, 'payroll.'),
            str_starts_with($route, 'purchase.') => BumiRole::Accountant,
            str_starts_with($route, 'bi.'),
            str_starts_with($route, 'analytics.'),
            str_starts_with($route, 'dashboard.') => BumiRole::Analyst,
            default => BumiRole::Assistant,
        };
    }

    /** @return array{string, string} контекст страницы и сценарий владельца курса */
    protected function pageContext(Request $request, string $route, BumiRole $role): array
    {
        if ($role !== BumiRole::Tutor && $role !== BumiRole::Clerk && $role !== BumiRole::Accountant && $role !== BumiRole::Analyst) {
            return ['', ''];
        }

        $employee = $request->user()?->employee;
        if (! $employee instanceof Employee) {
            return ['', ''];
        }

        $parts = [];

        try {
            if ($route === 'lms.lessons.show') {
                $lesson = $request->route('lesson');
                if ($lesson instanceof LmsLesson && (int) $lesson->company_id === (int) $employee->company_id) {
                    $course = $lesson->course;
                    $parts[] = __('Курс: :title', ['title' => (string) ($course?->title ?? '')]);
                    $parts[] = __('Урок: :title', ['title' => (string) $lesson->title]);
                    $summary = trim(strip_tags((string) ($lesson->summary ?? '')));
                    if ($summary !== '') {
                        $parts[] = $summary;
                    }

                    return [implode("\n", $parts), (string) ($course?->bumi_scenario ?? '')];
                }
            }

            if ($route === 'lms.courses.show') {
                $course = $request->route('course');
                if ($course instanceof LmsCourse && (int) $course->company_id === (int) $employee->company_id) {
                    $parts[] = __('Курс: :title', ['title' => (string) $course->title]);
                    $summary = trim(strip_tags((string) ($course->summary ?? '')));
                    if ($summary !== '') {
                        $parts[] = $summary;
                    }

                    return [implode("\n", $parts), (string) ($course->bumi_scenario ?? '')];
                }
            }

            if ($route === 'documents.show') {
                $document = $request->route('document');
                if ($document instanceof Document && (int) $document->company_id === (int) $employee->company_id) {
                    $parts[] = __('Документ: :title', ['title' => (string) ($document->title ?? '')]);
                    if (isset($document->status?->value)) {
                        $parts[] = __('Статус: :status', ['status' => (string) $document->status->value]);
                    }
                }
            }

            if (str_starts_with($route, 'finance.invoices.')) {
                $invoice = $request->route('invoice');
                if ($invoice !== null && (int) data_get($invoice, 'company_id') === (int) $employee->company_id) {
                    $parts[] = __('Счёт: :number', ['number' => (string) data_get($invoice, 'number', '')]);
                    $parts[] = __('Сумма: :amount', ['amount' => (string) data_get($invoice, 'total', '')]);
                }
            }

            if ($route === 'bi.dashboards.show') {
                $dashboard = $request->route('dashboard');
                if ($dashboard !== null && (int) data_get($dashboard, 'company_id') === (int) $employee->company_id) {
                    $parts[] = __('Дашборд: :title', ['title' => (string) data_get($dashboard, 'title', '')]);
                }
            }
        } catch (Throwable $e) {
            report($e);

            return ['', ''];
        }

        return [implode("\n", $parts), ''];
    }
}
