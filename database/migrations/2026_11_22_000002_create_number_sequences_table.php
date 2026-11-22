<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-07/DB-07/DB-08: атомарные номерные последовательности.
 *
 * Одна строка на (type, year); инкремент идёт под SELECT … FOR UPDATE внутри
 * транзакции вызывающего кода. Уникальный индекс не даёт двум konkurrирующим
 * транзакциям получить один и тот же номер.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 128);
            $table->string('year', 8);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
