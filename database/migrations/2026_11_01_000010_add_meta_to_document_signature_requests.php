<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('document_signature_requests', 'meta') === false) {
            Schema::table('document_signature_requests', function (Blueprint $table) {
                $table->json('meta')->nullable()->after('signers');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_signature_requests', 'meta')) {
            Schema::table('document_signature_requests', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
