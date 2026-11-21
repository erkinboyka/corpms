<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['projects', 'tasks', 'documents', 'lms_courses', 'lms_assignments'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'photo_path')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('photo_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'photo_path')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('photo_path');
                });
            }
        }
    }
};
