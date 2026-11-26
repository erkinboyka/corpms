<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Несколько OpenAI-совместимых провайдеров и ключей на компанию.
 *
 * Старые corpms.ai.* остаются fallback-ом: установки без ai_providers
 * работают как раньше, а новая таблица включается только когда администратор
 * добавил хотя бы одного провайдера.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_providers')) {
            return;
        }

        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('base_url', 500);
            $table->text('api_key')->nullable();
            $table->json('models')->nullable();
            $table->string('default_model', 120)->nullable();
            $table->string('image_model', 120)->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
