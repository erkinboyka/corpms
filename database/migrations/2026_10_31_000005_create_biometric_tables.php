<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('device_type', 32)->default('face_terminal');
            $table->string('vendor', 64)->default('generic');
            $table->string('model')->nullable();
            $table->string('serial_number', 64)->unique();
            $table->enum('direction', ['in', 'out', 'both'])->default('both');
            $table->enum('status', ['online', 'offline', 'disabled'])->default('offline');
            $table->string('api_token_hash', 128);
            $table->string('firmware')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('capabilities')->nullable(); // liveness, temperature, etc.
            $table->timestamps();
        });

        // Минимизация биометрии: храним только ссылку на шаблон у вендора, не raw-данные
        Schema::create('biometric_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('vendor', 64);
            $table->string('vendor_template_ref', 255)->nullable();
            $table->string('template_hash', 128)->nullable();
            $table->enum('status', ['pending', 'enrolled', 'disabled', 'deleted'])->default('pending');
            $table->foreignId('enrolled_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'vendor']);
        });

        Schema::create('biometric_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_uuid', 64)->unique(); // идемпотентность офлайн-синка
            $table->timestamp('occurred_at');
            $table->enum('direction', ['in', 'out']);
            $table->string('template_type', 32)->default('face');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('liveness_passed')->default(true);
            $table->boolean('liveness_required')->default(true);
            $table->decimal('temperature', 4, 1)->nullable();
            $table->string('image_ref', 255)->nullable(); // по retention-политике
            $table->json('raw_payload');
            $table->enum('processing_status', ['pending', 'processed', 'failed', 'skipped'])->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'occurred_at']);
            $table->index(['device_id', 'occurred_at']);
            $table->index('processing_status');
        });

        Schema::create('biometric_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('operated_by', 128)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('biometric_enrollments');
        Schema::dropIfExists('biometric_events');
        Schema::dropIfExists('biometric_profiles');
        Schema::dropIfExists('biometric_devices');
        Schema::enableForeignKeyConstraints();
    }
};
