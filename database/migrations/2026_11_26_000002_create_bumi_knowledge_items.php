<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * База знаний Bumi в БД: готовые пары вопрос/ответ и загруженные файлы.
 *
 * Используется как RAG-источник для ответов: поиск идёт только внутри компании
 * и конкретного агента, поэтому чужие знания не попадают в промпт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bumi_knowledge_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('bumi_agents')->cascadeOnDelete();
            $table->enum('kind', ['qa', 'file'])->default('qa');
            $table->string('title', 255);
            $table->text('question')->nullable();
            $table->longText('answer')->nullable();
            $table->string('filename', 255)->nullable();
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'agent_id', 'enabled'], 'bumi_knowledge_scope_idx');
            $table->index(['agent_id', 'kind'], 'bumi_knowledge_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bumi_knowledge_items');
    }
};
