<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('director_id')->nullable()->after('description')
                ->constrained('employees')->nullOnDelete();
            $table->foreignId('deputy_id')->nullable()->after('director_id')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('director_id');
            $table->dropConstrainedForeignId('deputy_id');
        });
    }
};
