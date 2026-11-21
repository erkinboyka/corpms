<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32); // company, state
            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('issuer')->nullable();
            $table->string('serial')->nullable();
            $table->string('fingerprint', 128)->unique();
            $table->longText('pem');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_certificates');
    }
};