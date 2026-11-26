<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offsite-заявки (п. 27–29 ТЗ).
 *
 * Ключевое правило: PENDING-заявка НЕ создаёт присутствия. Табель и история
 * статусов меняются только после APPROVED, при этом face-верификация не нужна.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_offsite_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'status', 'date']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            // work_type: office / offsite (значения зафиксированы в AttendanceWorkType)
            $table->string('work_type', 32)->nullable()->default('office')->after('source');
            $table->unsignedBigInteger('approved_by')->nullable()->after('corrected_by_employee_id');
            $table->text('notes')->nullable()->after('approved_by');
            $table->index(['work_type', 'date']);
        });

        Schema::create('attendance_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->date('date')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('work_type', 32)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
        });

        Schema::create('face_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('biometric_event_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('result', 32)->default('failed'); // success / failed / invalid_face
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('liveness_passed')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('face_verification_logs');
        Schema::dropIfExists('attendance_status_history');
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['work_type', 'date']);
            $table->dropColumn(['work_type', 'approved_by', 'notes']);
        });
        Schema::dropIfExists('attendance_offsite_requests');

        Schema::enableForeignKeyConstraints();
    }
};
