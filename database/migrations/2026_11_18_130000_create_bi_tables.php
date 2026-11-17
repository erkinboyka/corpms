<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Целевой модуль ТЗ «BI» (E6): витрина метрик, кастомные дашборды и виджеты.
     *
     * bi_metrics — дневная витрина: код метрики + дата + значение, собирается
     * из источников (финансы, CRM, задачи, склад, проекты) командой из UI.
     * Дашборды и виджеты хранят только конфигурацию: KPI или тренд по метрике,
     * значения всегда считаются из витрины (никакого дублирования факта).
     */
    public function up(): void
    {
        Schema::create('bi_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('code', 64);
            $table->decimal('value', 16, 4)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'metric_date', 'code']);
            $table->index(['company_id', 'code', 'metric_date']);
        });

        Schema::create('bi_dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'is_default']);
        });

        Schema::create('bi_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained('bi_dashboards')->cascadeOnDelete();
            $table->string('title');
            $table->enum('kind', ['kpi', 'trend'])->default('kpi');
            $table->string('metric_code', 64);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['dashboard_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bi_widgets');
        Schema::dropIfExists('bi_dashboards');
        Schema::dropIfExists('bi_metrics');
    }
};
