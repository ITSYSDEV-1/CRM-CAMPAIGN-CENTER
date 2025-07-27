<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmUnit;
use App\Models\QuotaUsage;
use App\Services\QuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class QuotaSyncController extends Controller
{
    protected $quotaService;

    public function __construct(QuotaService $quotaService)
    {
        $this->quotaService = $quotaService;
    }

    /**
     * Endpoint untuk unit mengirim data quota mereka
     * POST /api/quota/sync
     */
    public function syncQuotaFromUnit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string',
            'quota_data' => 'required|array',
            'quota_data.today_used' => 'required|integer|min:0',
            'quota_data.monthly_used' => 'required|integer|min:0',
            'quota_data.billing_cycle' => 'required|array',
            'quota_data.billing_cycle.start' => 'required|date',
            'quota_data.billing_cycle.end' => 'required|date',
            'sync_type' => 'required|in:scheduled,manual,initial'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $crmUnit = CrmUnit::where('app_code', $request->app_code)->first();
        
        if (!$crmUnit) {
            return response()->json([
                'success' => false,
                'message' => 'CRM Unit not found'
            ], 404);
        }

        try {
            $result = $this->processQuotaSync($crmUnit, $request->quota_data, $request->sync_type);
            
            return response()->json([
                'success' => true,
                'message' => 'Quota sync completed',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Proses sinkronisasi dengan check & balance
     */
    private function processQuotaSync($crmUnit, $quotaData, $syncType)
    {
        $today = now()->format('Y-m-d');
        $pepipostAccount = $crmUnit->pepipostAccount;
        
        // Ambil data quota saat ini dari database pusat
        $currentQuotaUsage = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('crm_unit_id', $crmUnit->id)
            ->where('usage_date', $today)
            ->first();
            
        $currentDailyUsed = $currentQuotaUsage ? $currentQuotaUsage->daily_used : 0;
        $currentMonthlyUsed = $this->getMonthlyUsage($pepipostAccount, $crmUnit);
        
        // Data dari unit
        $unitDailyUsed = $quotaData['today_used'];
        $unitMonthlyUsed = $quotaData['monthly_used'];
        
        // Check & Balance Logic
        $discrepancy = $this->checkDiscrepancy(
            $currentDailyUsed, 
            $unitDailyUsed, 
            $currentMonthlyUsed, 
            $unitMonthlyUsed
        );
        
        // Update atau create quota usage
        $quotaUsage = QuotaUsage::updateOrCreate(
            [
                'pepipost_account_id' => $pepipostAccount->id,
                'crm_unit_id' => $crmUnit->id,
                'usage_date' => $today
            ],
            [
                'daily_used' => $unitDailyUsed,
                'monthly_used' => $unitMonthlyUsed,
                'sync_type' => $syncType,
                'last_sync_at' => now(),
                'discrepancy_status' => $discrepancy['status'],
                'discrepancy_details' => $discrepancy['details'],
                'breakdown' => array_merge(
                    $quotaUsage->breakdown ?? [],
                    [[
                        'timestamp' => now()->toISOString(),
                        'type' => 'unit_sync',
                        'sync_type' => $syncType,
                        'daily_used' => $unitDailyUsed,
                        'monthly_used' => $unitMonthlyUsed
                    ]]
                )
            ]
        );
        
        return [
            'quota_usage_id' => $quotaUsage->id,
            'discrepancy' => $discrepancy,
            'updated_quota' => [
                'daily_used' => $quotaUsage->daily_used,
                'monthly_used' => $quotaUsage->monthly_used,
                'daily_remaining' => $crmUnit->daily_quota - $quotaUsage->daily_used,
                'monthly_remaining' => $crmUnit->monthly_quota - $quotaUsage->monthly_used
            ]
        ];
    }
    
    /**
     * Check discrepancy antara data pusat dan unit
     */
    private function checkDiscrepancy($centerDaily, $unitDaily, $centerMonthly, $unitMonthly)
    {
        $dailyDiff = abs($centerDaily - $unitDaily);
        $monthlyDiff = abs($centerMonthly - $unitMonthly);
        
        // Toleransi 5% atau maksimal 100 email
        $dailyTolerance = max(50, $centerDaily * 0.05);
        $monthlyTolerance = max(500, $centerMonthly * 0.05);
        
        $status = 'normal';
        $details = [];
        
        if ($dailyDiff > $dailyTolerance) {
            $status = 'warning';
            $details[] = [
                'type' => 'daily_discrepancy',
                'center_value' => $centerDaily,
                'unit_value' => $unitDaily,
                'difference' => $dailyDiff,
                'tolerance' => $dailyTolerance
            ];
        }
        
        if ($monthlyDiff > $monthlyTolerance) {
            $status = 'warning';
            $details[] = [
                'type' => 'monthly_discrepancy',
                'center_value' => $centerMonthly,
                'unit_value' => $unitMonthly,
                'difference' => $monthlyDiff,
                'tolerance' => $monthlyTolerance
            ];
        }
        
        return [
            'status' => $status,
            'details' => $details
        ];
    }
    
    private function getMonthlyUsage($pepipostAccount, $crmUnit)
    {
        return QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('crm_unit_id', $crmUnit->id)
            ->whereMonth('usage_date', now()->month)
            ->whereYear('usage_date', now()->year)
            ->sum('monthly_used');
    }
}