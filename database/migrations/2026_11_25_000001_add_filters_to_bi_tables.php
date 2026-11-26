<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bumi/BI tool calls: фильтры дашборда и отдельного виджета хранятся JSON-массивом.
 * Старые дашборды/виджеты получают NULL — это трактуется как пустой список.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bi_dashboards', function (Blueprint $table) {
            $table->json('filters')->nullable()->after('instruction');
        });

        Schema::table('bi_widgets', function (Blueprint $table) {
            $table->json('filters')->nullable()->after('query_spec');
        });
    }

    public function down(): void
    {
        Schema::table('bi_widgets', function (Blueprint $table) {
            $table->dropColumn('filters');
        });

        Schema::table('bi_dashboards', function (Blueprint $table) {
            $table->dropColumn('filters');
        });
    }
};
