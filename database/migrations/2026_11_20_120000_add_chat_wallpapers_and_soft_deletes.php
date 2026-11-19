<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->string('wallpaper_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->dropColumn('wallpaper_path');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
