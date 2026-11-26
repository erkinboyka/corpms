<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ТЗ п.16–19: у графика должны быть явные рабочие дни недели (ISO 1=Пн … 7=Вс).
 * Старые графики сохраняют привычный режим «Пн–Пт».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->json('work_days')->nullable()->after('type');
        });

        DB::table('work_schedules')
            ->whereNull('work_days')
            ->update(['work_days' => '[1,2,3,4,5]']);
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->dropColumn('work_days');
        });
    }
};
