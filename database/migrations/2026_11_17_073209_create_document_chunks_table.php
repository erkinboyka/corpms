<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D4 (RAG): чанки документов компании для AI-поиска.
 *
 * embedding — вектор OpenAI-совместимого /embeddings; null — провайдер
 * недоступен, поиск по чанку идёт keyword-сопоставлением (деградация).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
