<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DB-04: недостающие FK. Перед добавлением проверяем отсутствие «сирот». */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private const REFERENCES = [
        ['attendance_records', 'corrected_by_employee_id', 'employees'],
        ['tasks', 'document_id', 'documents'],
        ['documents', 'template_id', 'document_templates'],
        ['documents', 'task_id', 'tasks'],
        ['documents', 'current_version_id', 'document_versions'],
        ['conversation_members', 'last_read_message_id', 'messages'],
    ];

    public function up(): void
    {
        foreach (self::REFERENCES as [$table, $column, $referenced]) {
            $orphan = DB::table($table)
                ->whereNotNull($table.'.'.$column)
                ->leftJoin($referenced, $table.'.'.$column, '=', $referenced.'.id')
                ->whereNull($referenced.'.id')
                ->select($table.'.id')
                ->first();

            if ($orphan !== null) {
                throw new RuntimeException(sprintf(
                    'DB-04: orphaned %s.%s = %s. Clean data before adding the foreign key.',
                    $table,
                    $column,
                    (string) $orphan->{$column},
                ));
            }
        }

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreign('corrected_by_employee_id')->references('id')->on('employees')->nullOnDelete();
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('template_id')->references('id')->on('document_templates')->nullOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete();
        });
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->foreign('last_read_message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['corrected_by_employee_id']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropForeign(['task_id']);
            $table->dropForeign(['current_version_id']);
        });
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->dropForeign(['last_read_message_id']);
        });
    }
};
