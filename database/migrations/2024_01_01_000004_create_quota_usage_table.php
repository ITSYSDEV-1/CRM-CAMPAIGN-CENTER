<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pepipost_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('crm_unit_id')->nullable()->constrained()->onDelete('cascade');
            $table->date('usage_date');
            $table->integer('daily_used')->default(0);
            $table->integer('monthly_used')->default(0);
            $table->integer('mandatory_used')->default(0); // Penggunaan kuota wajib
            $table->json('breakdown')->nullable(); // Detail penggunaan per kampanye
            $table->timestamps();
            
            // Unique constraint untuk mencegah duplikasi
            $table->unique(['pepipost_account_id', 'crm_unit_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_usage');
    }
};