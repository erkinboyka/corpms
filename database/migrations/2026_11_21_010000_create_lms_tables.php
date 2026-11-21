<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('cover_color', 16)->default('#206bc4');
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::create('lms_lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('duration_min')->default(0);
            $table->string('kind', 24)->default('text');
            $table->boolean('is_required')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('lms_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('enrolled');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('lms_lesson_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
            $table->string('status', 24)->default('not_started');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'lesson_id']);
        });

        Schema::create('lms_whiteboards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'course_id']);
        });

        Schema::create('lms_whiteboard_elements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whiteboard_id')->constrained('lms_whiteboards')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('type', 24);
            $table->json('payload');
            $table->string('color', 24)->nullable();
            $table->unsignedInteger('width')->default(3);
            $table->boolean('is_erased')->default(false);
            $table->timestamps();

            $table->index(['whiteboard_id', 'id']);
        });

        Schema::create('lms_whiteboard_presence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whiteboard_id')->constrained('lms_whiteboards')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->float('x')->default(0);
            $table->float('y')->default(0);
            $table->string('color', 24)->default('#206bc4');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['whiteboard_id', 'employee_id']);
        });

        Schema::create('lms_meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->cascadeOnDelete();
            $table->string('title');
            $table->string('room', 32)->unique();
            $table->string('status', 24)->default('scheduled');
            $table->foreignId('host_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('lms_meeting_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained('lms_meetings')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24)->default('participant');
            $table->string('status', 24)->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'employee_id']);
        });

        Schema::create('lms_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained('lms_meetings')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('type', 24);
            $table->json('payload');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'id']);
        });

        Schema::create('lms_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('max_score')->default(100);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('lms_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('lms_assignments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'employee_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('lms_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('type', 40);
            $table->string('label', 255);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'lms_activities', 'lms_submissions', 'lms_assignments', 'lms_signals',
            'lms_meeting_participants', 'lms_meetings', 'lms_whiteboard_presence',
            'lms_whiteboard_elements', 'lms_whiteboards', 'lms_lesson_progress',
            'lms_enrollments', 'lms_lessons', 'lms_courses',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
