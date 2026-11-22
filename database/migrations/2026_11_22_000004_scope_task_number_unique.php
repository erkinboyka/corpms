<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-08: генератор TSK-номеров скоуплен по компании, поэтому и уникальность
 * должна быть (company_id, number). Глобальный индекс делал вторую компанию
 * неспособной создать задачу с тем же номером (500 вместо корректного номера).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_number_unique');
            $table->unique(['company_id', 'number'], 'tasks_company_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_company_number_unique');
            $table->unique('number', 'tasks_number_unique');
        });
    }
};
