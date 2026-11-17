<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('applicable_categories')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_number'); // внутренний Д-2026-000123
            $table->string('reg_number')->nullable(); // регистрационный номер при регистрации
            $table->foreignId('category_id')->constrained('document_categories')->restrictOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->enum('type', ['incoming', 'outgoing', 'internal'])->default('internal');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('confidentiality', ['public', 'internal', 'confidential', 'secret'])->default('internal');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable();
            $table->foreignId('task_id')->nullable();

            $table->enum('status', ['draft', 'submitted', 'review', 'revision', 'approved', 'signing', 'signed', 'registered', 'archived', 'rejected'])
                ->default('draft');

            $table->foreignId('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('document_number');
            $table->index(['status', 'confidentiality']);
            $table->index(['company_id', 'category_id']);
            $table->index(['task_id']);
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('document_categories')->restrictOnDelete();
            $table->string('name');
            $table->enum('kind', ['blade', 'docx'])->default('blade');
            $table->text('body');
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('workflow_id')->nullable()->constrained('document_workflows');
            $table->timestamps();
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64);
            $table->text('change_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('immutable')->default(false); // true после подписи соответствующей версии
            $table->timestamps();

            $table->unique(['document_id', 'version']);
        });

        Schema::create('document_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('document_workflows')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('name');
            $table->enum('actor_type', ['role', 'department', 'employee', 'author', 'ceo']);
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('required_action', ['approve', 'review', 'sign'])->default('approve');
            $table->boolean('is_signature_step')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();

            $table->unique(['workflow_id', 'step_order']);
        });

        Schema::create('document_workflows_pivot', function (Blueprint $table) {
            // текущее состояние документа в workflow
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('document_workflows')->cascadeOnDelete();
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->enum('state', ['not_started', 'in_progress', 'completed', 'failed'])->default('not_started');
            $table->timestamps();

            $table->unique(['document_id', 'workflow_id']);
            $table->index(['workflow_id', 'current_step']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('document_workflow_steps');
        Schema::dropIfExists('document_workflows');
        Schema::dropIfExists('document_workflows_pivot');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
        Schema::enableForeignKeyConstraints();
    }
};
