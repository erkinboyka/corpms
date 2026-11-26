<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История диалога Bumi-репетитора внутри урока LMS.
 *
 * Нужна и для coach-логики (счётчик подсказок), и для Pulse/дашборда:
 * каждое обращение к наставнику остаётся видимым событием обучения.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bumi_tutor_messages')) {
            return;
        }

        Schema::create('bumi_tutor_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12); // student|bumi
            $table->string('mode', 20)->default('explain'); // explain|hint|check
            $table->text('content');
            $table->string('mood', 24)->nullable();
            $table->string('reason', 40)->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'lesson_id', 'employee_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bumi_tutor_messages');
    }
};
