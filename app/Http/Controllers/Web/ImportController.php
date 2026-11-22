<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\Position;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ImportController
{
    public function index(Request $request): View
    {
        // SEC-03/BE-01: список заданий импорта — только текущий тенант.
        // Без привязки к сотруднику/компании отдаём пустой набор (fail-closed).
        $me = app(PermissionService::class)->employee($request->user());
        $companyId = $me?->company_id;

        $jobs = $companyId === null
            ? collect()
            : ImportJob::query()
                ->where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit(50)
                ->get();

        return view('imports.index', ['jobs' => $jobs]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => ['required', 'in:employees,departments'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $me = app(PermissionService::class)->employee($request->user());
        $path = $request->file('file')->store('imports');

        $job = ImportJob::create([
            'company_id' => $me?->company_id,
            'user_id' => $request->user()->id,
            'type' => $request->input('type'),
            'file_path' => $path,
            'status' => 'pending',
        ]);

        return redirect()->route('imports.show', $job);
    }

    public function show(ImportJob $job): View
    {
        $path = Storage::path($job->file_path);
        $preview = [];
        $header = [];
        if (is_file($path)) {
            $handle = fopen($path, 'r');
            if ($handle) {
                $header = fgetcsv($handle) ?: [];
                $i = 0;
                while (($row = fgetcsv($handle)) !== false && $i < 5) {
                    $preview[] = $row;
                    $i++;
                }
                fclose($handle);
            }
        }

        return view('imports.show', ['job' => $job, 'header' => $header, 'preview' => $preview]);
    }

    public function run(ImportJob $job): RedirectResponse
    {
        $job->update(['status' => 'running', 'started_at' => now()]);
        $me = app(PermissionService::class)->employee(auth()->user());
        $companyId = $job->company_id ?: $me?->company_id;
        $path = Storage::path($job->file_path);

        $errors = [];
        $total = 0;
        $imported = 0;
        $failed = 0;

        $handle = fopen($path, 'r');
        if (! $handle) {
            $job->update(['status' => 'failed', 'errors' => [__('Файл недоступен')]]);

            return redirect()->route('imports.show', $job);
        }

        $header = fgetcsv($handle);
        $map = array_flip($header ?: []);

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            try {
                if ($job->type === 'departments') {
                    $this->importDepartment($companyId, $row, $map);
                } else {
                    $this->importEmployee($companyId, $row, $map);
                }
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Строка {$total}: ".$e->getMessage();
            }
        }
        fclose($handle);

        $job->update([
            'status' => 'done',
            'total_rows' => $total,
            'imported_rows' => $imported,
            'failed_rows' => $failed,
            'errors' => $errors,
            'finished_at' => now(),
        ]);

        return redirect()->route('imports.show', $job);
    }

    private function val(array $row, array $map, string $key): ?string
    {
        if (! isset($map[$key])) {
            return null;
        }
        $v = $row[$map[$key]] ?? null;

        return $v === '' ? null : $v;
    }

    private function importDepartment(?int $companyId, array $row, array $map): void
    {
        $code = $this->val($row, $map, 'code');
        $name = $this->val($row, $map, 'name');
        if (! $code || ! $name || ! $companyId) {
            throw new \RuntimeException('Нет code/name/company');
        }
        $parentCode = $this->val($row, $map, 'parent_code');
        $parentId = null;
        if ($parentCode) {
            $parent = Department::where('company_id', $companyId)->where('code', $parentCode)->first();
            $parentId = $parent?->id;
        }
        Department::updateOrCreate(
            ['company_id' => $companyId, 'code' => $code],
            ['name' => $name, 'parent_id' => $parentId, 'is_active' => true]
        );
    }

    private function importEmployee(?int $companyId, array $row, array $map): void
    {
        $lastName = $this->val($row, $map, 'last_name');
        $firstName = $this->val($row, $map, 'first_name');
        if (! $lastName || ! $firstName || ! $companyId) {
            throw new \RuntimeException('Нет фамилии/имени/company');
        }
        $deptCode = $this->val($row, $map, 'department_code');
        $posCode = $this->val($row, $map, 'position_code');
        $departmentId = null;
        $positionId = null;
        if ($deptCode) {
            $departmentId = Department::where('company_id', $companyId)->where('code', $deptCode)->value('id');
        }
        if ($posCode) {
            $positionId = Position::where('company_id', $companyId)->where('code', $posCode)->value('id');
        }
        $employeeNo = $this->val($row, $map, 'employee_no') ?: ('IMP-'.uniqid());
        Employee::updateOrCreate(
            ['company_id' => $companyId, 'employee_no' => $employeeNo],
            [
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $this->val($row, $map, 'middle_name'),
                'email' => $this->val($row, $map, 'email'),
                'phone' => $this->val($row, $map, 'phone'),
                'hired_at' => $this->val($row, $map, 'hired_at'),
                'status' => 'active',
            ]
        );
    }
}
