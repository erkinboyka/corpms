<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TinyMCE сохраняет HTML, поэтому строковых 500 символов для описания мало.
 * Проверка типов БД: doctrine/dbal в проекте нет, меняем колонки напрямую.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bumi_agents') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `bumi_agents`
            MODIFY `description` TEXT NULL,
            MODIFY `system_prompt` LONGTEXT NOT NULL,
            MODIFY `knowledge` LONGTEXT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('bumi_agents') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `bumi_agents`
            MODIFY `description` VARCHAR(500) NULL,
            MODIFY `system_prompt` TEXT NOT NULL,
            MODIFY `knowledge` TEXT NULL');
    }
};
