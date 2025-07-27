<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_unit_id')->constrained()->onDelete('cascade');
            $table->foreignId('pepipost_account_id')->constrained()->onDelete('cascade');
            $table->date('scheduled_date');
            $table->integer('email_count');
            $table->string('campaign_type')->default('regular'); // regular, urgent, promotional
            $table->string('status')->default('pending'); // pending, approved, rejected, sent, cancelled
            $table->text('subject')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Data tambahan kampanye
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            
            // Index untuk performa
            $table->index(['scheduled_date', 'pepipost_account_id']);
            $table->index(['crm_unit_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};