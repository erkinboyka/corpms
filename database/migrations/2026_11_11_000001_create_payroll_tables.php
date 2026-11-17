<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Справочник компонентов начислений/удержаний компании (настраиваемые правила)
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->enum('kind', ['earning', 'deduction'])->default('earning');
            $table->enum('calculation', ['fixed', 'hourly', 'overtime', 'percent', 'manual'])->default('fixed');
            $table->decimal('rate', 12, 4)->default(0); // ставка: сумма/час/% — зависит от calculation
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        // Оклады сотрудников с периодом действия
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_amount', 12, 2);
            $table->string('currency', 3)->default('TJS');
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // null = действует бессрочно
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });

        // Расчётные периоды (ведомости)
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'calculated', 'approved', 'paid'])->default('draft');
            $table->decimal('gross_amount', 14, 2)->default(0);   // начислено
            $table->decimal('deduction_amount', 14, 2)->default(0); // удержано
            $table->decimal('net_amount', 14, 2)->default(0);     // к выплате
            $table->unsignedInteger('employees_count')->default(0);
            $table->dateTime('calculated_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'period_start']);
        });

        // Строки ведомости: одна строка = один компонент одного сотрудника
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('salary_components')->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->default(1);   // часы/единицы
            $table->decimal('rate', 12, 4)->default(0);       // ставка на единицу
            $table->decimal('amount', 14, 2);                 // итог по строке (положительный)
            $table->enum('source', ['auto', 'manual'])->default('auto');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('employee_salaries');
        Schema::dropIfExists('salary_components');
    }
};
