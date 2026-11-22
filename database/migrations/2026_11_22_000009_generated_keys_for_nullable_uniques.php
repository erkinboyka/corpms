<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-12: в MySQL несколько NULL в UNIQUE допустимы. Для связей, где NULL
 * означает «глобальная запись», используем generated key COALESCE(...,0).
 * client_id сообщений намеренно не трогается: NULL там означает отсутствие
 * idempotency key, и такие сообщения обязаны быть неуникальными.
 *
 * MySQL 8: VIRTUAL (не STORED), потому что базовые столбцы участвуют в FK
 * с ON DELETE SET NULL; STORED-generated над ними запрещён (error 1215).
 * Перед удалением старого unique добавляем отдельный индекс под FK, иначе
 * MySQL отказывается его удалять (error 1553).
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}> */
    private const SCOPES = [
        [
            'employee_roles',
            'department_id',
            'employee_roles_employee_id_role_id_department_id_unique',
            'employee_roles_employee_id_index',
            'employee_roles_scope_unique',
            'department_key',
            'employee_id, role_id',
        ],
        [
            'attendance_anomalies',
            'employee_id',
            'attendance_anomalies_employee_id_date_type_unique',
            'attendance_anomalies_employee_id_index',
            'attendance_anomalies_scope_unique',
            'employee_key',
            'date, type',
        ],
    ];

    public function up(): void
    {
        foreach (self::SCOPES as [$table, , , , , , $group]) {
            $duplicate = DB::table($table)
                ->selectRaw('COUNT(*) as cnt')
                ->whereNull($this->scopeColumn($table))
                ->groupByRaw($group)
                ->havingRaw('COUNT(*) > 1')
                ->first();

            if ($duplicate !== null) {
                throw new RuntimeException(
                    sprintf('DB-12: duplicate NULL-scoped rows in %s (%s). Resolve before migrating.', $table, $group),
                );
            }
        }

        foreach (self::SCOPES as [$table, $column, $oldIndex, $fkIndex, $newIndex, $keyColumn, $group]) {
            if (! Schema::hasColumn($table, $keyColumn)) {
                Schema::table($table, function (Blueprint $table) use ($column, $keyColumn): void {
                    $table->unsignedBigInteger($keyColumn)->virtualAs('coalesce('.$column.', 0)');
                });
            }

            // Старый unique мог быть единственным индексом для FK по employee_id.
            if (! Schema::hasIndex($table, $fkIndex)) {
                Schema::table($table, function (Blueprint $table) use ($fkIndex): void {
                    $table->index('employee_id', $fkIndex);
                });
            }

            if (Schema::hasIndex($table, $oldIndex)) {
                Schema::table($table, static function (Blueprint $table) use ($oldIndex): void {
                    $table->dropUnique($oldIndex);
                });
            }

            if (! Schema::hasIndex($table, $newIndex)) {
                Schema::table($table, static function (Blueprint $table) use ($keyColumn, $group, $newIndex): void {
                    $table->unique(array_merge([$keyColumn], array_map('trim', explode(',', $group))), $newIndex);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::SCOPES as [$table, , $oldIndex, $fkIndex, $newIndex, $keyColumn, $group]) {
            if (Schema::hasIndex($table, $newIndex)) {
                Schema::table($table, static function (Blueprint $table) use ($newIndex): void {
                    $table->dropUnique($newIndex);
                });
            }

            if (Schema::hasColumn($table, $keyColumn)) {
                Schema::table($table, static function (Blueprint $table) use ($keyColumn): void {
                    $table->dropColumn($keyColumn);
                });
            }

            if (! Schema::hasIndex($table, $oldIndex)) {
                Schema::table($table, static function (Blueprint $table) use ($group, $oldIndex): void {
                    $table->unique(array_map('trim', explode(',', $group)), $oldIndex);
                });
            }

            if (Schema::hasIndex($table, $fkIndex)) {
                Schema::table($table, static function (Blueprint $table) use ($fkIndex): void {
                    $table->dropIndex($fkIndex);
                });
            }
        }
    }

    private function scopeColumn(string $table): string
    {
        return $table === 'employee_roles' ? 'department_id' : 'employee_id';
    }
};
