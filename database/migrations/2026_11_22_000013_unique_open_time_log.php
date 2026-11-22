<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H-BL-04: у сотрудника может быть только один открытый интервал учёта
 * времени. MySQL не поддерживает partial unique, поэтому используем
 * generated key по образцу DB-12: NULL для закрытых логов.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('task_time_logs')
            ->select('employee_id')
            ->whereNull('stopped_at')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'H-BL-04: several open time logs for employee '.(string) $duplicate->employee_id.'. Resolve before migrating.',
            );
        }

        Schema::table('task_time_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('open_employee_key')
                ->nullable()
                // VIRTUAL: employee_id — база FK, MySQL не разрешает STORED над ним.
                ->virtualAs('case when stopped_at is null then employee_id else null end');
            $table->unique('open_employee_key', 'task_time_logs_one_open_per_employee');
        });
    }

    public function down(): void
    {
        Schema::table('task_time_logs', function (Blueprint $table): void {
            $table->dropUnique('task_time_logs_one_open_per_employee');
            $table->dropColumn('open_employee_key');
        });
    }
};
