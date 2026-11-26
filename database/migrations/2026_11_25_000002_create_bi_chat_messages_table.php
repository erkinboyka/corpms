<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История Bumi/BI и журнал инструментальных изменений дашборда.
 *
 * company_id дублируется намеренно: transcript имеет ту же границу тенанта,
 * что и дашборд, и не должен зависеть от цепочки связей при скоупинге.
 * payload хранит эффекты и undo-снимки, rating — оценку ответа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dashboard_id')->constrained('bi_dashboards')->cascadeOnDelete();
            $table->string('role', 12);
            $table->text('content');
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'dashboard_id', 'id']);
            $table->index(['dashboard_id', 'role', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_chat_messages');
    }
};
