<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_courses', static function (Blueprint $table): void {
            if (! Schema::hasColumn('lms_courses', 'bumi_scenario')) {
                // Сценарий репетитора задаёт владелец курса: Bumi работает
                // по этому сценарию, а не по случайной «персоне».
                $table->text('bumi_scenario')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lms_courses', static function (Blueprint $table): void {
            if (Schema::hasColumn('lms_courses', 'bumi_scenario')) {
                $table->dropColumn('bumi_scenario');
            }
        });
    }
};
