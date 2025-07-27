<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quota_usage', function (Blueprint $table) {
            $table->string('sync_type')->nullable()->after('breakdown');
            $table->timestamp('last_sync_at')->nullable()->after('sync_type');
            $table->enum('discrepancy_status', ['normal', 'warning'])->default('normal')->after('last_sync_at');
            $table->json('discrepancy_details')->nullable()->after('discrepancy_status');
        });
    }

    public function down(): void
    {
        Schema::table('quota_usage', function (Blueprint $table) {
            $table->dropColumn(['sync_type', 'last_sync_at', 'discrepancy_status', 'discrepancy_details']);
        });
    }
};