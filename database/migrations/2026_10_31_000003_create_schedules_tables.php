<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['standard', 'flexible', 'shift', 'custom'])->default('standard');
            $table->time('start_time')->default('08:00:00');
            $table->time('end_time')->default('17:00:00');
            $table->time('break_start')->default('12:00:00')->nullable();
            $table->time('break_end')->default('13:00:00')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->unsignedSmallInteger('grace_minutes')->default(10); // допустимое опоздание
            $table->boolean('allow_overtime')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        // Сменные графики: промежуточные смены по дням
        Schema::create('schedule_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 1..7
            $table->date('date')->nullable(); // точечная смена (переопределяет day_of_week)
            $table->time('start_time');
            $table->time('end_time');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->timestamps();

            $table->index(['work_schedule_id', 'day_of_week']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->boolean('is_day_off')->default(true); // выходной vs сокращённый
            $table->timestamps();

            $table->unique(['company_id', 'date']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['vacation', 'sick', 'business_leave', 'day_off', 'other']);
            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'from_date', 'to_date']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('work_schedule_id')->references('id')->on('work_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::enableForeignKeyConstraints();
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('schedule_shifts');
        Schema::dropIfExists('schedule_assignments');
        Schema::dropIfExists('work_schedules');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['work_schedule_id']);
        });
        Schema::disableForeignKeyConstraints();
    }
};
