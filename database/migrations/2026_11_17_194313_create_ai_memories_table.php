<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D6 (AI Memory): долговременная память AI-ассистента о сотруднике.
 *
 * Каждая запись — пара key/value в пределах сотрудника (employee_id + key unique).
 * Пишется только по явной команде пользователя («запомни…»), не автоматически.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('memory_key', 64);
            $table->string('memory_value', 500);
            // откуда запись: user (явная команда) | system
            $table->string('source', 16)->default('user');
            $table->timestamps();

            $table->unique(['employee_id', 'memory_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
    }
};
