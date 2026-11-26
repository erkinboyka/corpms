<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAG: модель, которой снят вектор чанка.
 *
 * Одинаковая длина ещё не означает совместимость: text-embedding-3-small и
 * -3-large могут возвращать 1536 чисел из разных векторных пространств.
 * Поиск пропускает чанки с чужой моделью или длиной и предлагает реиндекс.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', static function (Blueprint $table): void {
            $table->string('embedding_model', 120)->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', static function (Blueprint $table): void {
            $table->dropColumn('embedding_model');
        });
    }
};
