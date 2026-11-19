<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // Тенант: диалог не существует вне компании
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['direct', 'group', 'channel'])->default('direct');
            $table->enum('visibility', ['private', 'company'])->default('private');
            $table->string('title')->nullable();
            $table->string('avatar_path')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            // Кто может писать в канал: все участники или только администраторы
            $table->enum('post_permission', ['all', 'admins'])->default('all');
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'last_message_at']);
        });

        Schema::create('conversation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['member', 'admin', 'owner'])->default('member');
            // Для каналов: отписка не удаляет строку, а скрывает диалог
            $table->boolean('is_subscribed')->default(true);
            // Граница прочитанного — по ней считаются непрочитанные
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'employee_id']);
            $table->index(['employee_id', 'left_at']);
            $table->index(['conversation_id', 'last_read_message_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // Автор может быть удалён — сообщение остаётся в истории
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('kind', ['text', 'file', 'system'])->default('text');
            $table->text('body')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            // Идемпотентность клиента: повтор при обрыве связи не создаёт копию
            $table->string('client_id', 64)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['conversation_id', 'client_id']);
            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'pinned_at']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('file_name');
            $table->string('stored_path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_image')->default(false);
            $table->timestamps();

            $table->index('message_id');
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'employee_id', 'emoji']);
        });

        Schema::create('message_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->cascadeOnDelete();
            $table->enum('type', [
                'created', 'updated', 'deleted', 'read', 'reaction',
                'member_joined', 'member_left', 'pinned',
            ]);
            $table->foreignId('actor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id']);
            $table->index(['message_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_events');
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_members');
        Schema::dropIfExists('conversations');
    }
};
