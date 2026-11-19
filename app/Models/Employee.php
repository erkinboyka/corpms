<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\EmployeeStatus;
use App\Support\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Employee extends Model
{
    protected $table = 'employees';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    use SoftDeletes;

    protected static function booted(): void
    {
        static::saved(function (self $employee): void {
            app(PermissionService::class)->forget();
            if ($employee->wasChanged('status') && $employee->status !== EmployeeStatus::Active && $employee->user_id !== null) {
                // Remembered logins are revoked even when the employee has no active browser request.
                User::find($employee->user_id)?->revokeSessions();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))
                        ->table(config('session.table', 'sessions'))->where('user_id', $employee->user_id)->delete();
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'hired_at' => 'date',
            'fired_at' => 'date',
            'birth_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'employee_roles', 'employee_id', 'role_id');
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(EmployeePermissionOverride::class, 'employee_id');
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function taskAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'author_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'signer_id');
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class, 'employee_id');
    }

    public function biometricProfiles(): HasMany
    {
        return $this->hasMany(BiometricProfile::class, 'employee_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'recipient_id');
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class, 'employee_id');
    }

    public function employeeSalaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class, 'employee_id');
    }

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'employee_id');
    }
}
