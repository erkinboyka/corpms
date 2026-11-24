<?php

declare(strict_types=1);

use App\Shared\Enums\FinTransactionSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROC-01: финансовое обязательство перед поставщиком и его.opлаты.
 *
 * Обязательство создаётся при размещении заказа (в том же контейнере), сумма
 * берётся из total заказа. Оплата — расход в журнале; уникальный reference
 * внутри обязательства не даёт провести одну платёжку дважды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_transactions', function (Blueprint $table): void {
            $table->enum('source', array_column(FinTransactionSource::cases(), 'value'))
                ->default(FinTransactionSource::Manual->value)
                ->change();
        });

        Schema::create('purchase_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->unique()->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('number', 32);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('TJS');
            $table->enum('status', ['open', 'partially_paid', 'paid', 'cancelled'])->default('open');
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('purchase_obligation_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obligation_id')->constrained('purchase_obligations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('fin_transactions')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            $table->enum('method', ['cash', 'bank_transfer', 'card', 'other'])->default('bank_transfer');
            $table->string('reference', 64);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['obligation_id', 'reference']);
            $table->index(['company_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_obligation_payments');
        Schema::dropIfExists('purchase_obligations');
        Schema::table('fin_transactions', function (Blueprint $table): void {
            $table->enum('source', ['manual', 'invoice_payment'])
                ->default('manual')
                ->change();
        });
    }
};
