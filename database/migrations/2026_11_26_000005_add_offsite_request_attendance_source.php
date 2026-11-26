<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ТЗ п.22: OFFSITE + OFFSITE_REQUEST и SYSTEM — отдельные источники отметок.
 * Расширяем enum attendance_records.source, не переписывая существующие записи.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const LEGACY = ['biometric', 'qr', 'kiosk', 'manual'];

    /** @var array<int, string> */
    private const VALUES = [...self::LEGACY, 'offsite_request', 'system'];

    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->enum('source', self::VALUES)->default('biometric')->change();
        });
    }

    public function down(): void
    {
        $unsupported = DB::table('attendance_records')
            ->whereIn('source', ['offsite_request', 'system'])
            ->count();
        if ($unsupported > 0) {
            throw new \RuntimeException(
                'Откат невозможен: в табеле есть записи с источником offsite_request/system. '
                .'Сначала переведите их на поддерживаемый источник.',
            );
        }

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->enum('source', self::LEGACY)->default('biometric')->change();
        });
    }
};
