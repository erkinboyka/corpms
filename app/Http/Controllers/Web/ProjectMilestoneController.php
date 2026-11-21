<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Projects\Services\ProjectService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Вехи проекта (Этап E5): контрольные точки со сроком и отметкой выполнения.
 */
class ProjectMilestoneController extends Controller
{
    public function __construct(
        protected ProjectService $projects,
        protected AuditService $audit,
    ) {}

    /** Добавление вехи. */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
        ], [], ['title' => __('название'), 'due_date' => __('срок')]);

        $milestone = $this->projects->addMilestone(
            $project,
            $validated['title'],
            $validated['due_date'] ?? null,
        );

        $this->audit->log($request->user(), 'project.milestone_added', 'project', $project->id, null, [
            'milestone_id' => $milestone->id,
            'title' => $milestone->title,
        ]);

        return back()->with('status', __('Веха «').$milestone->title.__('» добавлена'));
    }

    /** Отметка вехи: выполнена/возвращена в работу. */
    public function toggle(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);
        TenantGuard::ensure($milestone, $me);

        $this->projects->toggleMilestone($milestone);

        $this->audit->log($request->user(), 'project.milestone_toggled', 'project', $project->id, null, [
            'milestone_id' => $milestone->id,
            'completed' => $milestone->completed_at !== null,
        ]);

        return back()->with(
            'status',
            __('Веха «').$milestone->title.'» '.($milestone->completed_at !== null ? __('выполнена') : __('возвращена в работу')),
        );
    }

    /** Удаление вехи. */
    public function destroy(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);
        TenantGuard::ensure($milestone, $me);

        $milestone->delete();

        $this->audit->log($request->user(), 'project.milestone_removed', 'project', $project->id, null, [
            'milestone_id' => $milestone->id,
        ]);

        return back()->with('status', __('Веха удалена'));
    }
}
