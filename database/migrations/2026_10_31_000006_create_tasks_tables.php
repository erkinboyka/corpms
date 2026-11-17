<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['task', 'order', 'document_task', 'approval_task', 'document_prep', 'document_sign', 'document_review', 'service'])
                ->default('task');
            $table->string('number')->unique();
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->timestamp('deadline')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('document_id')->nullable();
            $table->json('meta')->nullable();
            $table->enum('status', ['new', 'planned', 'in_progress', 'review', 'returned', 'completed', 'cancelled','overdue'])
                ->default('new');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'deadline']);
            $table->index(['department_id', 'status']);
            $table->index(['company_id', 'type']);
            $table->index('parent_id');
        });

        // Назначения: сотруднику / отделу / группе
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->enum('assignee_type', ['employee', 'department']);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            // Исполнитель основной / соисполнитель / наблюдатель
            $table->enum('role', ['assignee', 'coassignee'])->default('assignee');
            $table->enum('assignment_status', ['pending', 'accepted', 'in_progress', 'review', 'completed', 'returned', 'rejected'])
                ->default('pending');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'assignment_status']);
            $table->index(['employee_id', 'assignment_status']);
        });

        Schema::create('task_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'employee_id']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('task_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        Schema::create('task_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('task_assignments')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete(); // зависит от
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->enum('dependency_type', ['finish_to_start', 'start_to_start', 'blocks'])->default('finish_to_start');
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id']);
        });

        Schema::create('task_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->timestamps();

            $table->index(['task_id', 'employee_id']);
        });

        Schema::create('task_results', function (Blueprint $table) {
            // агрегат: задача должна собрать результаты отделов (п.14)
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('task_results');
        Schema::dropIfExists('task_time_logs');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('task_delegations');
        Schema::dropIfExists('task_status_history');
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('tasks');
        Schema::enableForeignKeyConstraints();
    }
};
