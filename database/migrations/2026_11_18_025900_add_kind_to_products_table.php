<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Вид номенклатуры (товар/услуга): услуги не участвуют в складских остатках,
     * но могут входить в закупочные заказы.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('kind', ['goods', 'service'])->default('goods')->after('name');
            $table->index(['company_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
