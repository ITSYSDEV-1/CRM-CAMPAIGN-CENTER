<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_units', function (Blueprint $table) {
            $table->id();
            $table->string('app_code')->unique(); // RCD, RMS, KSV, dll
            $table->string('name'); // Nama unit hotel
            $table->foreignId('pepipost_account_id')->constrained()->onDelete('cascade');
            $table->integer('daily_quota')->default(0); // Kuota harian unit
            $table->integer('monthly_quota')->default(0); // Kuota bulanan unit
            $table->integer('mandatory_daily_quota')->default(0); // Kuota wajib harian
            $table->integer('max_sync_per_day')->default(5); // Maksimal sync per hari
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // Pengaturan dinamis
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_units');
    }
};