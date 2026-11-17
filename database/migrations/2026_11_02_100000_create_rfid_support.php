<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // device_type: добавляем rfid_reader (п.72 ТЗ)
        Schema::table('biometric_devices', function (Blueprint $table): void {
            $table->enum('device_type', [
                'face_terminal',
                'kiosk',
                'fingerprint_gate',
                'turnstile',
                'qr_reader',
                'rfid_reader',
            ])->default('face_terminal')->change();
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
        Schema::dropIfExists('rfid_cards');

        Schema::table('biometric_devices', function (Blueprint $table): void {
            $table->enum('device_type', [
                'face_terminal',
                'kiosk',
                'fingerprint_gate',
                'turnstile',
                'qr_reader',
            ])->default('face_terminal')->change();
        });
    }
};
