<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('locale');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->string('google_id')->nullable()->unique()->after('two_factor_confirmed_at');
            $table->string('auth_provider', 16)->default('password')->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'google_id', 'auth_provider']);
        });
    }
};