<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Analytics\Services\AnalyticsService;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Support\PermissionService;
use App\Support\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Глобальный поиск (п.65.x ТЗ): сотрудники, задачи, документы в пределах scope.
 */
class SearchController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected PermissionService $permissions,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();
        \assert($user instanceof User);
        // company-scope = «вся моя компания»: ищем только по своей компании
        $companyId = $user->employee?->company_id;

        $search = SearchQuery::fromRequest($request);
        $q = $search->value;
        $employees = collect();
        $tasks = collect();
        $documents = collect();

        if ($q !== '') {
            if ($this->permissions->check($user, 'employees.view')) {
                $access = $this->analytics->accessibleEmployees($user, 'employees.view');
                $needle = mb_strtolower($q);
                $employees = $access['employees']
                    ->filter(static fn (Employee $e) => str_contains(
                        mb_strtolower(trim($e->last_name.' '.$e->first_name.' '.(string) $e->middle_name.' '.$e->employee_no)),
                        $needle,
                    ))
                    ->take(20);
            }

            if ($this->permissions->check($user, 'tasks.view')) {
                $access = $this->analytics->accessibleEmployees($user, 'tasks.view');
                $ids = $access['employees']->pluck('id')->all();

                $tasks = Task::query()
                    ->with('author')
                    ->where('company_id', $companyId)
                    ->where(fn ($w) => $search->applyLike($w, ['title', 'number']))
                    ->when($access['scope_value'] !== 'company', fn ($w) => $w
                        ->whereHas('assignments', fn ($a) => $a->whereIn('employee_id', $ids)->limit(1)))
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get();
            }

            if ($this->permissions->check($user, 'documents.view')) {
                $access = $this->analytics->accessibleEmployees($user, 'documents.view');
                $ids = $access['employees']->pluck('id')->all();

                $documents = Document::query()
                    ->with('category')
                    ->where('company_id', $companyId)
                    ->where(fn ($w) => $search->applyLike($w, ['title', 'document_number', 'reg_number']))
                    ->when($access['scope_value'] !== 'company', fn ($w) => $w
                        ->where(fn ($s) => $s->whereIn('author_id', $ids)
                            ->orWhereHas('signatureRequests', fn ($r) => $r->whereIn('signer_id', $ids))))
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get();
            }
        }

        return view('search.index', compact('q', 'employees', 'tasks', 'documents'));
    }

    /**
     * Быстрые подсказки для живого поиска в топбаре (JSON, максимум 4+4+4).
     */
    public function hint(Request $request): JsonResponse
    {
        $user = auth()->user();
        \assert($user instanceof User);
        $companyId = $user->employee?->company_id;

        $search = SearchQuery::fromRequest($request);
        $q = $search->value;
        if (mb_strlen($q) < 2) {
            return response()->json(['query' => $q, 'employees' => [], 'tasks' => [], 'documents' => []]);
        }

        $needle = mb_strtolower($q);
        $employees = [];
        $tasks = [];
        $documents = [];

        if ($this->permissions->check($user, 'employees.view')) {
            $access = $this->analytics->accessibleEmployees($user, 'employees.view');
            $employees = $access['employees']
                ->filter(static fn (Employee $e) => str_contains(
                    mb_strtolower(trim($e->last_name.' '.$e->first_name.' '.(string) $e->middle_name.' '.$e->employee_no)),
                    $needle,
                ))
                ->take(4)
                ->map(static fn (Employee $e) => [
                    'title' => trim($e->last_name.' '.$e->first_name),
                    'meta' => $e->position?->name ?? $e->employee_no,
                    'url' => route('employees.show', $e),
                ])->values()->all();
        }

        if ($this->permissions->check($user, 'tasks.view')) {
            $access = $this->analytics->accessibleEmployees($user, 'tasks.view');
            $ids = $access['employees']->pluck('id')->all();

            $tasks = Task::query()
                ->where('company_id', $companyId)
                ->where(fn ($w) => $search->applyLike($w, ['title', 'number']))
                ->when($access['scope_value'] !== 'company', fn ($w) => $w
                    ->whereHas('assignments', fn ($a) => $a->whereIn('employee_id', $ids)->limit(1)))
                ->orderByDesc('id')
                ->limit(4)
                ->get()
                ->map(static fn (Task $t) => [
                    'title' => $t->title,
                    'meta' => $t->number ?? (__('Задача #').$t->id),
                    'url' => route('tasks.show', $t),
                ])->all();
        }

        if ($this->permissions->check($user, 'documents.view')) {
            $access = $this->analytics->accessibleEmployees($user, 'documents.view');
            $ids = $access['employees']->pluck('id')->all();

            $documents = Document::query()
                ->where('company_id', $companyId)
                ->where(fn ($w) => $search->applyLike($w, ['title', 'document_number', 'reg_number']))
                ->when($access['scope_value'] !== 'company', fn ($w) => $w
                    ->where(fn ($s) => $s->whereIn('author_id', $ids)
                        ->orWhereHas('signatureRequests', fn ($r) => $r->whereIn('signer_id', $ids))))
                ->orderByDesc('id')
                ->limit(4)
                ->get()
                ->map(static fn (Document $d) => [
                    'title' => $d->title,
                    'meta' => $d->reg_number ?? $d->document_number ?? (__('Документ #').$d->id),
                    'url' => route('documents.show', $d),
                ])->all();
        }

        return response()->json([
            'query' => $q,
            'employees' => $employees,
            'tasks' => $tasks,
            'documents' => $documents,
        ]);
    }
}
