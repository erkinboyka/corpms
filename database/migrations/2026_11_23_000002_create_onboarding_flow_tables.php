<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONB-01: оркестратор найма.
 *
 * Один поток на сотрудника, шаги и выданные роли хранятся отдельно, чтобы
 * повтор события не дублировал побочные эффекты, а отзыв снимал только те
 * роли, которые поток действительно выдал.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('onboarding_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('onboarding_flows')->cascadeOnDelete();
            $table->string('key', 32);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['flow_id', 'key']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('onboarding_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('onboarding_flows')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->boolean('pre_existing')->default(false);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['flow_id', 'role_id']);
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_access_grants');
        Schema::dropIfExists('onboarding_steps');
        Schema::dropIfExists('onboarding_flows');
    }
};
