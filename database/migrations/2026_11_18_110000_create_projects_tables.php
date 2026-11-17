<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Целевой модуль ТЗ «Projects» (E5): проекты, участники (ресурсы),
     * вехи и связь с задачами и финансовыми операциями (бюджет проекта).
     *
     * Бюджет проекта не хранит «факт»: он считается по fin_transactions.project_id,
     * поэтому проект видит реальные расходы бухгалтерии/финансов.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['planned', 'active', 'on_hold', 'completed', 'cancelled'])->default('planned');
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('budget', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['manager', 'member', 'observer'])->default('member');
            $table->unsignedTinyInteger('allocation_percent')->default(100);
            $table->timestamps();
            $table->unique(['project_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'completed_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('department_id')->constrained('projects')->nullOnDelete();
            $table->index(['company_id', 'project_id']);
        });

        Schema::table('fin_transactions', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('category_id')->constrained('projects')->nullOnDelete();
            $table->index(['company_id', 'project_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_transactions', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['company_id', 'project_id']);
            $table->dropColumn('project_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['company_id', 'project_id']);
            $table->dropColumn('project_id');
        });

        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
