<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Task;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use Illuminate\Database\Eloquent\Builder;

final class BumiRecords
{
    public static function tasks(Employee $me): Builder
    {
        $scope = app(PermissionService::class)->can($me->user, 'tasks.view');
        $query = Task::query()->where('company_id', $me->company_id);
        if (!$scope) return $query->whereRaw('1 = 0');
        if ($scope === PermissionScope::Company) return $query;
        return $query->where(function ($q) use ($me, $scope) {
            $q->where('author_id', $me->id)->orWhereHas('assignments', function ($a) use ($me) {
                $a->where(fn ($s) => $s->where('employee_id', $me->id)
                    ->when($me->department_id !== null, fn ($d) => $d->orWhere('department_id', $me->department_id)));
            });
            if ($scope === PermissionScope::Department && $me->department_id !== null) $q->orWhere('department_id', $me->department_id);
        });
    }

    public static function documents(Employee $me): Builder
    {
        $scope = app(PermissionService::class)->can($me->user, 'documents.view');
        $query = Document::query()->where('company_id', $me->company_id);
        if (!$scope) return $query->whereRaw('1 = 0');
        if ($scope === PermissionScope::Company) return $query;
        return $query->where(function ($q) use ($me, $scope) {
            $q->where('author_id', $me->id);
            if ($scope === PermissionScope::Department && $me->department_id !== null) {
                $q->orWhere('department_id', $me->department_id);
            } elseif ($scope === PermissionScope::Own) {
                $q->orWhereHas('signatureRequests', fn ($s) => $s->where('signer_id', $me->id));
            }
        });
    }
}
