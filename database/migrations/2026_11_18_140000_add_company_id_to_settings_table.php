<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки становятся межтенантными: строка с company_id — переопределение
 * конкретной компании, строка с company_id NULL — общая (установочная)
 * настройка, используется как fallback. Существующие записи остаются
 * общими (company_id NULL) — поведение не меняется до первой правки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained('companies')->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique(['group', 'key']);
            $table->unique(['group', 'key', 'company_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('settings')->whereNotNull('company_id')->exists()) {
            throw new RuntimeException('Cannot remove tenant scope while company settings exist. Export and resolve them first.');
        }
        // Возврат к unique(group, key) возможен только без перезаписей
        // настроек на уровне компаний (иначе дубли общих ключей).
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique(['group', 'key', 'company_id']);
            $table->unique(['group', 'key']);
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
