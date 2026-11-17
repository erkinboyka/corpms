<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Единый журнал переходов статусной машины (Этап B: Workflow Engine):
        // документы, заявки на отпуск, зарплатные ведомости — одна история.
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);  // document | leave_request | payroll_period
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status', 32)->nullable(); // null — создание субъекта
            $table->string('to_status', 32);
            $table->string('action', 32);        // submit | approve | register | pay | ...
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment', 1000)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id'], 'wt_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
