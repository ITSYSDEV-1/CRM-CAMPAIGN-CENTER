<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pepipost_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Akun 1, Akun 2, dll
            $table->string('api_key')->nullable();
            $table->integer('daily_quota')->default(0);
            $table->integer('monthly_quota')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // Pengaturan tambahan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pepipost_accounts');
    }
};