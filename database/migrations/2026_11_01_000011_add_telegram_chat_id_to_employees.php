<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'telegram_chat_id') === false) {
            Schema::table('employees', function (Blueprint $table) {
                // chat_id выдаётся при /start в боте; nullable — уведомления опциональны
                $table->string('telegram_chat_id', 64)->nullable()->index()->after('phone');
            });
        }

        if (Schema::hasColumn('employees', 'telegram_link_code') === false) {
            Schema::table('employees', function (Blueprint $table) {
                // одноразовый код привязки (/start <code>): TTL в TelegramService
                $table->string('telegram_link_code', 32)->nullable()->index();
                $table->timestamp('telegram_link_expires_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['telegram_link_expires_at', 'telegram_link_code', 'telegram_chat_id'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                Schema::table('employees', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
