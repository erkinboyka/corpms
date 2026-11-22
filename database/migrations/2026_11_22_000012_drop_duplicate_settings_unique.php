<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-S4: после введения settings_scope_unique старый unique
 * (group, key, company_id) стал избыточным — удаляем дубль.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('settings', 'settings_group_key_company_id_unique')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique('settings_group_key_company_id_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('settings', 'settings_group_key_company_id_unique')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table): void {
            $table->unique(['group', 'key', 'company_id'], 'settings_group_key_company_id_unique');
        });
    }
};
