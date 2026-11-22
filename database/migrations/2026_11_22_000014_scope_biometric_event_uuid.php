<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BE-S1: event_uuid биометрии уникален в пределах компании, а не глобально.
 * Один и тот же UUID на устройствах разных арендаторов не должен блокировать запись.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_events', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('device_id')->constrained()->cascadeOnDelete();
        });

        // Бэкфилл из устройств: связь device_id -> company_id обязательна.
        foreach (DB::table('biometric_devices')->select('id', 'company_id')->get() as $device) {
            DB::table('biometric_events')
                ->where('device_id', $device->id)
                ->update(['company_id' => $device->company_id]);
        }

        Schema::table('biometric_events', function (Blueprint $table) {
            // generated key защищает от нескольких NULL в MySQL-UNIQUE.
            // VIRTUAL: company_id — база FK, MySQL не разрешает STORED над ним.
            $table->unsignedBigInteger('company_key')->virtualAs('coalesce(company_id, 0)');
            $table->dropUnique(['event_uuid']);
            $table->unique(['company_key', 'event_uuid'], 'biometric_events_company_event_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_events', function (Blueprint $table) {
            $table->dropUnique('biometric_events_company_event_uuid_unique');
            $table->dropColumn('company_key');
        });

        Schema::table('biometric_events', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->unique(['event_uuid']);
        });
    }
};
