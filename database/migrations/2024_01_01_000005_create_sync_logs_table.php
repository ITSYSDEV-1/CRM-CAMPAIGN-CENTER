<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_unit_id')->constrained()->onDelete('cascade');
            $table->date('sync_date');
            $table->integer('sync_count')->default(1);
            $table->string('sync_type')->default('manual'); // manual, auto
            $table->json('sync_data')->nullable(); // Data yang disinkronkan
            $table->timestamps();
            
            $table->index(['crm_unit_id', 'sync_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};