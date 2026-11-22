<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-S3: audit_logs получает tenant-ключ и составной индекс под запросы
 * дашборда/аналитики (фильтр по компании + периоду). Существующие строки
 * бэкфилятся через сотрудника пользователя; системные записи остаются NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('user_id')
                ->constrained('companies')->nullOnDelete();
        });

        // Корреляционный подзапрос переносим и на MySQL, и на SQLite.
        DB::table('audit_logs')
            ->whereNull('company_id')
            ->update([
                'company_id' => DB::raw(
                    '(select employees.company_id from employees '
                    .'where employees.user_id = audit_logs.user_id limit 1)',
                ),
            ]);

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['company_id', 'created_at'], 'audit_logs_company_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_company_id_created_at_index');
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
