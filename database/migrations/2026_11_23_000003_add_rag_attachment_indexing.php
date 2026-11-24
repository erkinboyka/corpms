<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAG-01: текстовый слой вложений, источник чанка и версия индекса.
 *
 * document_chunks.source — 'document' (title/body), 'file:pdf'/'file:docx'
 * (текстовый слой) или 'file:pdf+ocr'/'file:image+ocr'/'file:docx+ocr';
 * source_version — sha256 версии файла, document_version_id — её id.
 * document_rag_indexes хранит состояние индекса: отпечаток исходных
 * данных, номер поколения индекса и результат извлечения текста.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->unsignedInteger('index_version')->default(1)->after('chunk_index');
            $table->string('source', 32)->default('document')->after('content');
            $table->string('source_version', 64)->nullable()->after('source');
            $table->unsignedBigInteger('document_version_id')->nullable()->after('source_version');
            $table->json('metadata')->nullable()->after('document_version_id');

            $table->index(['company_id', 'index_version']);
            $table->index(['document_id', 'source']);
        });

        Schema::create('document_rag_indexes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('index_version')->default(1);
            $table->string('fingerprint', 64);
            $table->enum('status', ['indexed', 'empty', 'unsupported', 'failed'])->default('indexed');
            $table->string('extraction_status', 16)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_rag_indexes');

        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'index_version']);
            $table->dropIndex(['document_id', 'source']);
            $table->dropColumn([
                'index_version',
                'source',
                'source_version',
                'document_version_id',
                'metadata',
            ]);
        });
    }
};
