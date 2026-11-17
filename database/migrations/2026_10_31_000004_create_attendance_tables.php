<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->enum('source', ['biometric', 'qr', 'kiosk', 'manual'])->default('biometric');

            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('scheduled_minutes')->default(0);

            $table->enum('status', ['on_time', 'late', 'absent', 'early_leave', 'overtime', 'present', 'weekend', 'holiday'])
                ->default('present');
            $table->json('schedule_snapshot')->nullable();
            $table->foreignId('corrected_by_employee_id')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'date']); // один табельный день = одна запись
            $table->index('date');
            $table->index('status');
        });

        Schema::create('attendance_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->timestamps();

            $table->index(['attendance_record_id', 'started_at']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('field'); // clock_in_at / clock_out_at / break /
            $table->dateTime('old_value')->nullable();
            $table->dateTime('new_value')->nullable();
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('attendance_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->enum('type', ['missing_checkout', 'no_show', 'duplicate', 'direction_mismatch', 'unrecognized']);
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'resolved', 'ignored'])->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date', 'type']);
            $table->index(['status', 'date']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('attendance_anomalies');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_breaks');
        Schema::dropIfExists('attendance_records');
        Schema::enableForeignKeyConstraints();
    }
};
