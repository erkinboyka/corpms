<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Достраивает реквизиты, которые контроллеры и виды уже ждут, а схема не имела:
 *  - компании: ИНН / регион / часовой пояс (вводились формой создания);
 *  - должности: грейд и описание;
 *  - графики: минимальный межсменный отдых (влияет на сменные режимы);
 *  - праздники: вид (выходной / работает / сокращённый) и «перенесено с».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('inn', 32)->nullable()->after('code');
            $table->string('region', 255)->nullable()->after('email');
            $table->string('timezone', 64)->nullable()->after('region');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->string('grade', 32)->nullable()->after('code');
            $table->text('description')->nullable()->after('grade');
        });

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_rest_hours')->nullable()->after('grace_minutes');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->enum('kind', ['public', 'operating', 'shortened'])->default('public')->after('date');
            $table->date('transferred_from')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['inn', 'region', 'timezone']);
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['grade', 'description']);
        });
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn('min_rest_hours');
        });
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['kind', 'transferred_from']);
        });
    }
};
