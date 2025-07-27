<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\QuotaSyncController;

// Public endpoint untuk health check
Route::get('/ping', fn () => response()->json([
    'message' => 'pong',
    'timestamp' => now()->toISOString(),
    'version' => '1.0.0'
]));

// Protected API endpoints
Route::middleware('verify.api.token')->group(function () {
    
    // Campaign Management
    Route::prefix('schedule')->group(function () {
        Route::get('/overview', [CampaignController::class, 'overview']);
        Route::get('/overview/range', [CampaignController::class, 'overviewRange']); 
        Route::post('/request', [CampaignController::class, 'requestCampaign']);
        Route::delete('/cancel/{campaignId}', [CampaignController::class, 'cancelCampaign']);
    });
    
    // Quota Management
    Route::prefix('quota')->group(function () {
        Route::get('/status', [CampaignController::class, 'quotaStatus']);
    });
    
    // Campaign Completion - NEW ENDPOINT
    Route::post('/campaign/complete', [QuotaSyncController::class, 'markCampaignComplete']);
    
    // Sync Management
    Route::post('/sync', [CampaignController::class, 'sync']);
    
    // Campaign History
    Route::get('/campaigns/history', [CampaignController::class, 'history']);
    
    Route::post('/quota/sync', [QuotaSyncController::class, 'syncQuotaFromUnit']);
    Route::get('/quota/group-info', [QuotaSyncController::class, 'getGroupQuotaInfo']);
    Route::get('/quota/discrepancy', [QuotaSyncController::class, 'getDiscrepancyReport']);
    Route::get('/quota/discrepancy/summary', [QuotaSyncController::class, 'getDiscrepancySummary']);

    // Admin endpoints (optional, untuk management)
    Route::prefix('admin')->group(function () {
        Route::get('/units', [AdminController::class, 'getUnits']);
        Route::post('/units', [AdminController::class, 'createUnit']);
        Route::put('/units/{id}', [AdminController::class, 'updateUnit']);
        Route::get('/accounts', [AdminController::class, 'getAccounts']);
        Route::post('/accounts', [AdminController::class, 'createAccount']);
        Route::put('/accounts/{id}', [AdminController::class, 'updateAccount']);
    });
});
