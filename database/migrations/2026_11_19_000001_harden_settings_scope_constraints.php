<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->select('group', 'key')->whereNull('company_id')->groupBy('group', 'key')->havingRaw('COUNT(*) > 1')->first() !== null) {
            throw new RuntimeException('Duplicate global settings must be resolved before migrating; no data was deleted.');
        }
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
        Schema::table('settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('scope_id')->virtualAs('coalesce(company_id, 0)');
            $table->unique(['group', 'key', 'scope_id'], 'settings_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique('settings_scope_unique');
            $table->dropColumn('scope_id');
        });
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });
    }
};
