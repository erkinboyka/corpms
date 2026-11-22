<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DB-06: одна строка = один (период, сотрудник, компонент, источник). */
return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('payroll_lines')
            ->selectRaw('period_id, employee_id, component_id, source, COUNT(*) as cnt')
            ->groupBy('period_id', 'employee_id', 'component_id', 'source')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                sprintf(
                    'DB-06: duplicate payroll lines found (period=%d, employee=%d, component=%d, source=%s). Resolve duplicates before migrating.',
                    $duplicate->period_id,
                    $duplicate->employee_id,
                    $duplicate->component_id,
                    $duplicate->source,
                ),
            );
        }

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->unique(
                ['period_id', 'employee_id', 'component_id', 'source'],
                'payroll_lines_period_employee_component_source_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropUnique('payroll_lines_period_employee_component_source_unique');
        });
    }
};
