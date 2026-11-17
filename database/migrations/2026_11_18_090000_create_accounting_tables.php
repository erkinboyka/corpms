<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Целевой модуль ТЗ «Accounting» (E4): план счетов, журнал проводок,
     * строки проводок (дебет/кредит) и первичные документы.
     *
     * Проводка всегда сбалансирована (сумма дебета = сумма кредита) — это
     * проверяет доменный сервис; база хранит только проверенные записи.
     * Связь с Finance: у проводки может быть finance_transaction_id, и одна
     * операция финансов даёт ровно одну проводку (уникальный индекс).
     */
    public function up(): void
    {
        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->enum('kind', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'kind']);
        });

        Schema::create('acc_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->enum('source', ['manual', 'finance'])->default('manual');
            $table->foreignId('finance_transaction_id')->nullable()->constrained('fin_transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'finance_transaction_id']);
            $table->index(['company_id', 'entry_date']);
        });

        Schema::create('acc_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('acc_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('acc_accounts')->cascadeOnDelete();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['entry_id', 'account_id']);
        });

        Schema::create('acc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->enum('kind', ['invoice', 'act', 'payment_order', 'other'])->default('other');
            $table->date('document_date');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('counterparty')->nullable();
            $table->foreignId('entry_id')->nullable()->constrained('acc_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'document_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acc_documents');
        Schema::dropIfExists('acc_entry_lines');
        Schema::dropIfExists('acc_entries');
        Schema::dropIfExists('acc_accounts');
    }
};
