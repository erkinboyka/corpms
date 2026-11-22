<?php

use App\Shared\Enums\BiometricDeviceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // device_type: добавляем rfid_reader (п.72 ТЗ)
        Schema::table('biometric_devices', function (Blueprint $table): void {
            $table->enum('device_type', BiometricDeviceType::values())
                ->default(BiometricDeviceType::FaceTerminal->value)
                ->change();
        });

        Schema::create('rfid_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('card_uid', 64)->unique(); // UID карты (HEX/десятичный)
            $table->string('label', 120)->nullable(); // подпись (пропуск №1, ...)
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        // Значения, существовавшие до этой миграции. Откат не должен молча
        // сужать enum и терять rfid_reader у уже зарегистрированных устройств.
        $legacyTypes = [
            'face_terminal',
            'kiosk',
            'fingerprint_gate',
            'turnstile',
            'qr_reader',
        ];

        $unsupported = DB::table('biometric_devices')
            ->whereNotIn('device_type', $legacyTypes)
            ->count();
        if ($unsupported > 0) {
            throw new RuntimeException(
                'Откат RFID-миграции невозможен: найдены устройства с типом вне исходного enum (rfid_reader). '
               .'Сначала переведите их на поддерживаемый тип.',
            );
        }

        Schema::dropIfExists('rfid_cards');

        Schema::table('biometric_devices', function (Blueprint $table) use ($legacyTypes): void {
            $table->enum('device_type', $legacyTypes)
                ->default('face_terminal')
                ->change();
        });
    }
};
