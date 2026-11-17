<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Согласование: каждый шаг workflow → запись approval
        Schema::create('document_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained('document_workflow_steps')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'revision_requested', 'skipped'])->default('pending');
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'status']);
        });

        Schema::create('document_signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->enum('driver', ['internal', 'qr_approval', 'certificate', 'external_qualified'])->default('internal');
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('signer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('signer_role_slug', 64)->nullable(); // подписант по роли (CEO)
            $table->integer('order_index')->default(1);
            $table->json('signers')->nullable(); // несколько подписантов
            $table->json('meta')->nullable(); // OTP-код, попытки, доп контекст
            $table->enum('status', ['pending', 'awaiting_confirmation', 'signed', 'declined', 'revision', 'cancelled', 'partial'])
                ->default('pending');
            $table->text('decline_reason')->nullable();
            $table->boolean('requires_otp')->default(false);
            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['signer_id', 'status']);
        });

        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained('document_signature_requests')->cascadeOnDelete();
            $table->foreignId('signer_id')->constrained('employees')->cascadeOnDelete();
            $table->string('signature_id', 64)->unique(); // SIG-2026-000001
            $table->string('document_hash', 64); // sha256 версии на момент подписи
            $table->string('signature_hash', 128);
            $table->enum('signature_type', ['internal', 'qr_approval', 'certificate', 'external_qualified']);
            $table->enum('status', ['valid', 'revoked', 'invalid'])->default('valid'); // invalid = hash документа не совпадает
            $table->timestamp('signed_at');
            $table->string('ip', 45);
            $table->string('user_agent', 512)->nullable();
            $table->string('session_id', 64);
            $table->json('meta')->nullable(); // OTP подтверждение, device info
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['document_id', 'signed_at']);
        });

        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 128)->unique();
            $table->enum('kind', ['profile', 'badge', 'card', 'signing'])->default('profile');
            $table->enum('status', ['active', 'revoked', 'blocked'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedSmallInteger('generation')->default(1);
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 128)->unique();
            $table->nullableMorphs('subject'); // document, employee_id, ...
            $table->string('purpose', 32); // sign / approve / attendance_check / profile
            $table->boolean('one_time')->default(true);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64)->index();
            $table->nullableMorphs('object');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 64)->index();
            $table->string('key', 128);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::create('attendance_notifications', function (Blueprint $table) {
            // in-app уведомления (перед Laravel notification выское дорожное чтение)
            $table->id();
            $table->foreignId('recipient_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->nullableMorphs('subject');
            $table->string('channel', 32)->default('app'); // app / telegram / email
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'read_at']);
        });

        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('chat_id', 64)->unique();
            $table->string('username', 64)->nullable();
            $table->string('verification_code', 8)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->text('answer')->nullable();
            $table->json('query_used')->nullable(); // какие SQL/данные AI фактически использовал
            $table->boolean('grounded')->default(true); // ответ только из реальных БД
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('telegram_links');
        Schema::dropIfExists('attendance_notifications');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('qr_tokens');
        Schema::dropIfExists('qr_codes');
        Schema::dropIfExists('document_signatures');
        Schema::dropIfExists('document_signature_requests');
        Schema::dropIfExists('document_approvals');
        Schema::enableForeignKeyConstraints();
    }
};
