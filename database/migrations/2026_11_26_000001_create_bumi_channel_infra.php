<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Каналы обращений Bumi и автоответы: агенты, каналы, контакты, сообщения.
 *
 * Каждая таблица несёт company_id с первого дня — внешние сообщения не могут
 * «протечь» в другой тенант. Лиды создаются с источником канала, поэтому
 * расширяем enum crm_leads.source. Индексы называем явно: автоматически
 * сгенерированное имя тройного уникального индекса превышает лимит MySQL в 64 символа.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Свежие БД уже получают расширенный enum в create_crm_tables. Для
        // существующей MySQL меняем колонку напрямую: обычный change() для
        // enum требует doctrine/dbal, который в проекте не установлен.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `crm_leads` MODIFY `source` ENUM('web','call','referral','event','telegram','instagram','whatsapp','other') NOT NULL DEFAULT 'other'");
        }

        if (! Schema::hasTable('bumi_agents')) {
            Schema::create('bumi_agents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('slug', 80);
                $table->string('name');
                $table->string('description', 500)->nullable();
                $table->text('system_prompt');
                $table->text('knowledge')->nullable();
                $table->text('greeting')->nullable();
                $table->text('fallback_reply')->nullable();
                $table->string('model', 120)->nullable();
                $table->boolean('enabled')->default(true);
                $table->boolean('auto_reply')->default(true);
                $table->boolean('create_leads')->default(true);
                $table->unsignedInteger('max_replies_per_hour')->default(20);
                $table->foreignId('default_owner_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'slug'], 'bumi_agent_company_slug_unique');
                $table->index(['company_id', 'enabled'], 'bumi_agent_company_enabled_idx');
            });
        }

        if (! Schema::hasTable('bumi_channels')) {
            Schema::create('bumi_channels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('agent_id')->constrained('bumi_agents')->cascadeOnDelete();
                $table->enum('type', ['website', 'telegram', 'whatsapp', 'instagram', 'greenapi']);
                $table->string('name');
                $table->string('public_token', 64)->unique('bumi_channel_token_unique');
                $table->text('credentials')->nullable();
                $table->text('greeting')->nullable();
                $table->boolean('enabled')->default(false);
                $table->boolean('auto_reply')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'type'], 'bumi_channel_company_type_unique');
                $table->index(['company_id', 'enabled'], 'bumi_channel_company_enabled_idx');
            });
        }

        if (! Schema::hasTable('bumi_channel_contacts')) {
            Schema::create('bumi_channel_contacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('channel_id')->constrained('bumi_channels')->cascadeOnDelete();
                $table->string('external_id', 255);
                $table->string('display_name', 255)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('email')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
                $table->foreignId('crm_client_id')->nullable()->constrained('crm_clients')->nullOnDelete();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['channel_id', 'external_id'], 'bumi_contact_channel_ext_unique');
                $table->index(['company_id', 'last_seen_at'], 'bumi_contact_company_seen_idx');
            });
        }

        if (! Schema::hasTable('bumi_channel_messages')) {
            Schema::create('bumi_channel_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('channel_id')->constrained('bumi_channels')->cascadeOnDelete();
                $table->foreignId('agent_id')->constrained('bumi_agents')->cascadeOnDelete();
                $table->foreignId('contact_id')->constrained('bumi_channel_contacts')->cascadeOnDelete();
                $table->string('external_message_id', 255)->nullable();
                $table->enum('direction', ['in', 'out']);
                $table->text('text');
                $table->json('payload')->nullable();
                $table->string('status', 32)->default('received');
                $table->unsignedBigInteger('reply_to_id')->nullable();
                $table->timestamps();

                $table->unique(['channel_id', 'direction', 'external_message_id'], 'bumi_msg_chan_dir_ext_unique');
                $table->index(['channel_id', 'id', 'direction'], 'bumi_msg_chan_id_dir_idx');
                $table->index(['company_id', 'created_at'], 'bumi_msg_company_created_idx');
            });
        } else {
            // Частично применённая миграция: таблица уже есть, а длинное имя
            // уникального индекса MySQL отклонил. Добираем недостающие индексы.
            $needUnique = ! Schema::hasIndex('bumi_channel_messages', 'bumi_msg_chan_dir_ext_unique', 'unique');
            $needChannel = ! Schema::hasIndex('bumi_channel_messages', 'bumi_msg_chan_id_dir_idx');
            $needCompany = ! Schema::hasIndex('bumi_channel_messages', 'bumi_msg_company_created_idx');

            if ($needUnique || $needChannel || $needCompany) {
                Schema::table('bumi_channel_messages', function (Blueprint $table) use ($needUnique, $needChannel, $needCompany): void {
                    if ($needUnique) {
                        $table->unique(['channel_id', 'direction', 'external_message_id'], 'bumi_msg_chan_dir_ext_unique');
                    }
                    if ($needChannel) {
                        $table->index(['channel_id', 'id', 'direction'], 'bumi_msg_chan_id_dir_idx');
                    }
                    if ($needCompany) {
                        $table->index(['company_id', 'created_at'], 'bumi_msg_company_created_idx');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bumi_channel_messages');
        Schema::dropIfExists('bumi_channel_contacts');
        Schema::dropIfExists('bumi_channels');
        Schema::dropIfExists('bumi_agents');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `crm_leads` MODIFY `source` ENUM('web','call','referral','event','other') NOT NULL DEFAULT 'other'");
        }
    }
};
