<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширение BI-таблиц под генерацию дашбордов ИИ (перенос пайплайна Datavue):
 * статусы/версия/инструкция у дашборда, query_spec и семейство виджета у
 * виджета. Существующие KPI/тренд-виджеты продолжают работать как раньше —
 * metric_code становится nullable, а kind остаётся прежним.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bi_dashboards', function (Blueprint $table) {
            $table->string('status', 16)->default('ready');
            $table->string('origin', 16)->default('manual');
            $table->unsignedInteger('version')->default(1);
            $table->text('instruction')->nullable();
            $table->text('last_error')->nullable();
        });

        Schema::table('bi_widgets', function (Blueprint $table) {
            $table->string('metric_code', 64)->nullable()->change();
            $table->string('widget_family', 32)->nullable();
            $table->string('widget_type', 32)->nullable();
            $table->string('content_mode', 16)->default('metric');
            $table->unsignedTinyInteger('span')->default(4);
            $table->text('instruction')->nullable();
            $table->json('query_spec')->nullable();
            $table->string('status', 16)->default('ready');
            $table->text('last_error')->nullable();
            $table->timestamp('last_run_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bi_widgets', function (Blueprint $table) {
            $table->dropColumn([
                'widget_family',
                'widget_type',
                'content_mode',
                'span',
                'instruction',
                'query_spec',
                'status',
                'last_error',
                'last_run_at',
            ]);
        });

        Schema::table('bi_dashboards', function (Blueprint $table) {
            $table->dropColumn(['status', 'origin', 'version', 'instruction', 'last_error']);
        });
    }
};
