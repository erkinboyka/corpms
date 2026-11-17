<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Товары/номенклатура компании.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64); // артикул/код
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 16)->default('шт'); // единица измерения
            $table->decimal('price', 14, 2)->default(0); // цена закупки
            $table->decimal('min_stock', 14, 3)->default(0); // минимальный остаток
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
