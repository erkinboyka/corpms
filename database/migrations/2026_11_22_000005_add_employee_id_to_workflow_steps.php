<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** BL-21: шаг типа «employee» должен назначать конкретного сотрудника, а не автора. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_workflow_steps', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('department_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_workflow_steps', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
