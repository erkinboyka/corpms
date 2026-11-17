<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Целевой модуль ТЗ «Finance» (E3): касса/счета, статьи, доходы/расходы,
     * счета-фактуры и акты, платежи по счетам, месячные бюджеты.
     *
     * Все таблицы с company_id — изоляция тенантов с первого дня; баланс счёта
     * не хранится, а считается как opening_balance + приход − расход журнала.
     */
    public function up(): void
    {
        Schema::create('fin_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('kind', ['cash', 'bank'])->default('bank');
            $table->string('currency', 3)->default('RUB');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('fin_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('direction', ['income', 'expense']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'direction']);
        });

        Schema::create('fin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $table->enum('direction', ['income', 'expense']);
            $table->decimal('amount', 14, 2);
            $table->date('occurred_at');
            $table->string('description')->nullable();
            $table->enum('source', ['manual', 'invoice_payment'])->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'direction', 'occurred_at']);
        });

        Schema::create('fin_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('crm_clients')->nullOnDelete();
            $table->string('counterparty_name')->nullable();
            $table->string('number', 32);
            $table->enum('kind', ['invoice', 'act'])->default('invoice');
            $table->enum('status', ['draft', 'issued', 'paid', 'cancelled'])->default('draft');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('fin_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('fin_invoices')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('fin_transactions')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            $table->enum('method', ['cash', 'bank_transfer', 'card', 'other'])->default('bank_transfer');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'paid_at']);
        });

        Schema::create('fin_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->enum('direction', ['income', 'expense']);
            $table->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $table->decimal('planned_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fin_budgets');
        Schema::dropIfExists('fin_payments');
        Schema::dropIfExists('fin_invoices');
        Schema::dropIfExists('fin_transactions');
        Schema::dropIfExists('fin_categories');
        Schema::dropIfExists('fin_accounts');
    }
};
