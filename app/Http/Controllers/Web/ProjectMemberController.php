<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Projects\Services\ProjectService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Shared\Enums\ProjectMemberRole;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Ресурсы проекта (Этап E5): участники, их роль и загрузка в процентах.
 */
class ProjectMemberController extends Controller
{
    public function __construct(
        protected ProjectService $projects,
        protected AuditService $audit,
    ) {}

    /** Добавление/обновление участника проекта. */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'role' => ['required', Rule::enum(ProjectMemberRole::class)],
            'allocation_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ], [], ['employee_id' => __('сотрудник'), 'role' => __('роль')]);

        TenantGuard::ensureReference('employees', (int) $validated['employee_id'], $me);

        $member = $this->projects->addMember(
            $project,
            (int) $validated['employee_id'],
            ProjectMemberRole::from($validated['role']),
            (int) ($validated['allocation_percent'] ?? 100),
        );

        $this->audit->log($request->user(), 'project.member_added', 'project', $project->id, null, [
            'employee_id' => $member->employee_id,
            'role' => $member->role->value,
        ]);

        return back()->with('status', __('Участник добавлен в проект'));
    }

    /** Удаление участника проекта. */
    public function destroy(Request $request, Project $project, ProjectMember $member): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($project, $me);
        TenantGuard::ensure($member, $me);

        try {
            $this->projects->removeMember($project, $member);
        } catch (LogicException $exception) {
            return back()->withErrors(['employee_id' => $exception->getMessage()]);
        }

        $this->audit->log($request->user(), 'project.member_removed', 'project', $project->id, null, [
            'employee_id' => $member->employee_id,
        ]);

        return back()->with('status', __('Участник удалён из проекта'));
    }
}
