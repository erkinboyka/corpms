<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DB-07: уникальность регистрационного номера в пределах компании. */
return new class extends Migration
{
    public function up(): void
    {
        // Если в базе уже есть дубли из-за старой гонки — не молча их чиним,
        // а прямо сообщаем: данные нужно разобрать вручную до включения индекса.
        $duplicate = DB::table('documents')
            ->select('company_id', 'reg_number', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('reg_number')
            ->groupBy('company_id', 'reg_number')
            ->having('cnt', '>', 1)
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'DB-07: в documents есть дубли reg_number для компании '.$duplicate->company_id
                .' ('.$duplicate->reg_number.'). Устраните дубли вручную перед применением индекса.'
            );
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->unique(['company_id', 'reg_number'], 'documents_company_reg_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_company_reg_number_unique');
        });
    }
};
