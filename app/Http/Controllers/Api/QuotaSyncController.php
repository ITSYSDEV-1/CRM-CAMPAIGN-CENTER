<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmUnit;
use App\Models\QuotaUsage;
use App\Services\QuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Services\BillingCycleService;

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
        
        // Ambil data existing terlebih dahulu
        $existingQuotaUsage = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
        ->where('crm_unit_id', $crmUnit->id)
        ->where('usage_date', $today)
        ->first();
        
        // Siapkan breakdown history
        $existingBreakdown = $existingQuotaUsage ? ($existingQuotaUsage->breakdown ?? []) : [];
        $newBreakdownEntry = [
        'timestamp' => now()->toISOString(),
        'type' => 'unit_sync',
        'sync_type' => $syncType,
        'daily_used' => $unitDailyUsed,
        'monthly_used' => $unitMonthlyUsed,
        'discrepancy_status' => $discrepancy['status'],
        'discrepancy_details' => $discrepancy['details']
        ];
        
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
        'breakdown' => array_merge($existingBreakdown, [$newBreakdownEntry])
        ]
        );
        
        // PERBAIKAN: Gunakan group quota untuk perhitungan remaining
        $groupDailyRemaining = $pepipostAccount->daily_quota - $unitDailyUsed;
        $groupMonthlyRemaining = $pepipostAccount->monthly_quota - $unitMonthlyUsed;
        
        // Validasi: pastikan available_daily campaign center tidak lebih besar dari group remaining
        $campaignCenterAvailableDaily = $pepipostAccount->getAvailableDailyQuota($today);
        // ✅ PERBAIKAN: Gunakan tanggal untuk billing period
        $campaignCenterAvailableMonthly = $pepipostAccount->getAvailableMonthlyQuota($today);
        
        // Check jika ada inkonsistensi
        $validation = [];
        if ($campaignCenterAvailableDaily > $groupDailyRemaining) {
            $validation[] = [
                'type' => 'daily_quota_inconsistency',
                'message' => 'Campaign center available daily quota is higher than group remaining',
                'campaign_center_available' => $campaignCenterAvailableDaily,
                'group_remaining' => $groupDailyRemaining,
                'difference' => $campaignCenterAvailableDaily - $groupDailyRemaining
            ];
        }
        
        if ($campaignCenterAvailableMonthly > $groupMonthlyRemaining) {
            $validation[] = [
                'type' => 'monthly_quota_inconsistency',
                'message' => 'Campaign center available monthly quota is higher than group remaining',
                'campaign_center_available' => $campaignCenterAvailableMonthly,
                'group_remaining' => $groupMonthlyRemaining,
                'difference' => $campaignCenterAvailableMonthly - $groupMonthlyRemaining
            ];
        }
        
        return [
            'quota_usage_id' => $quotaUsage->id,
            'discrepancy' => $discrepancy,
            'validation' => $validation,
            'updated_quota' => [
                'daily_used' => $quotaUsage->daily_used,
                'monthly_used' => $quotaUsage->monthly_used,
                'daily_remaining' => $groupDailyRemaining, // PERBAIKAN: Gunakan group quota
                'monthly_remaining' => $groupMonthlyRemaining // PERBAIKAN: Gunakan group quota
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
    
    private function getMonthlyUsage($pepipostAccount, $crmUnit, $date = null)
    {
        $billingPeriod = BillingCycleService::getBillingPeriod($date);
        
        return QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('crm_unit_id', $crmUnit->id)
            ->whereBetween('usage_date', [$billingPeriod['start_date'], $billingPeriod['end_date']])
            ->sum('monthly_used');
    }
    
    /**
     * Get group quota info untuk validasi
     */
    public function getGroupQuotaInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string',
            'date' => 'nullable|date'
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
    
        $date = $request->date ?? now()->format('Y-m-d');
        $pepipostAccount = $crmUnit->pepipostAccount;
        
        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'group_quota' => [
                    'account_name' => $pepipostAccount->name,
                    'daily_quota' => $pepipostAccount->daily_quota,
                    'monthly_quota' => $pepipostAccount->monthly_quota,
                    'available_daily' => $pepipostAccount->getAvailableDailyQuota($date),
                    'available_monthly' => $pepipostAccount->getAvailableMonthlyQuota($date)  // ✅ Pass date instead of month/year
                ]
            ]
        ]);
    }

/**
 * Endpoint untuk unit menandai kampanye sudah selesai
 * POST /api/campaign/complete
 */
public function markCampaignComplete(Request $request)
{
    $validator = Validator::make($request->all(), [
        'app_code' => 'required|string',
        'campaign_id' => 'required|integer|exists:campaigns,id',
        'actual_sent' => 'required|integer|min:0',
        'completion_date' => 'required|date',
        'completion_details' => 'sometimes|array'
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
        $result = $this->processCampaignCompletion($crmUnit, $request->all());
        
        return response()->json([
            'success' => true,
            'message' => 'Campaign marked as completed and quota updated',
            'data' => $result
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Campaign completion failed: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Proses penyelesaian kampanye dan update quota_usage
 */
private function processCampaignCompletion($crmUnit, $data)
{
    $pepipostAccount = $crmUnit->pepipostAccount;
    $campaignId = $data['campaign_id'];
    $actualSent = $data['actual_sent'];
    $completionDate = $data['completion_date'];
    
    // Cari kampanye yang akan diselesaikan
    $campaign = \App\Models\Campaign::where('id', $campaignId)
        ->where('crm_unit_id', $crmUnit->id)
        ->where('pepipost_account_id', $pepipostAccount->id)
        ->whereIn('status', ['approved', 'pending'])
        ->first();
        
    if (!$campaign) {
        throw new \Exception('Campaign not found or already completed');
    }
    
\Illuminate\Support\Facades\DB::beginTransaction();
    
    try {
        // 1. Update campaign status menjadi 'sent'
        $campaign->update([
            'status' => 'sent',
            'actual_sent' => $actualSent,
            'sent_at' => now(),
            'completion_details' => $data['completion_details'] ?? null
        ]);
        
        // 2. Update atau create quota_usage untuk tanggal completion
        $today = Carbon::parse($completionDate)->format('Y-m-d');
        
        // PERBAIKAN: Gunakan billing period instead of calendar month
        $billingPeriod = BillingCycleService::getBillingPeriod($completionDate);
        
        // Ambil quota usage yang ada
        $quotaUsage = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('crm_unit_id', $crmUnit->id)
            ->where('usage_date', $today)
            ->first();
            
        // PERBAIKAN: Hitung total monthly usage berdasarkan billing period
        $existingMonthlyUsage = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('crm_unit_id', $crmUnit->id)
            ->whereBetween('usage_date', [$billingPeriod['start_date'], $billingPeriod['end_date']])
            ->where('usage_date', '!=', $today) // Exclude today
            ->sum('daily_used');
            
        $newDailyUsed = ($quotaUsage ? $quotaUsage->daily_used : 0) + $actualSent;
        $newMonthlyUsed = $existingMonthlyUsage + $newDailyUsed;
        
        // Update atau create quota usage
        $quotaUsage = QuotaUsage::updateOrCreate(
            [
                'pepipost_account_id' => $pepipostAccount->id,
                'crm_unit_id' => $crmUnit->id,
                'usage_date' => $today
            ],
            [
                'daily_used' => $newDailyUsed,
                'monthly_used' => $newMonthlyUsed,
                'sync_type' => 'campaign_completion',
                'last_sync_at' => now(),
                'breakdown' => array_merge(
                    $quotaUsage->breakdown ?? [],
                    [[
                        'timestamp' => now()->toISOString(),
                        'type' => 'campaign_completion',
                        'campaign_id' => $campaignId,
                        'actual_sent' => $actualSent,
                        'completion_date' => $completionDate
                    ]]
                )
            ]
        );
        
        \Illuminate\Support\Facades\DB::commit();
        
        return [
            'campaign_id' => $campaignId,
            'campaign_status' => 'sent',
            'actual_sent' => $actualSent,
            'quota_usage_updated' => [
                'quota_usage_id' => $quotaUsage->id,
                'daily_used' => $quotaUsage->daily_used,
                'monthly_used' => $quotaUsage->monthly_used,
                'completion_date' => $completionDate
            ]
        ];
        
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $e;
    }
}

/**
 * GET /api/quota/discrepancy
 * Laporan discrepancy antara data pusat dan unit
 */
public function getDiscrepancyReport(Request $request)
{
    $validator = Validator::make($request->all(), [
        'app_code' => 'sometimes|string|exists:crm_units,app_code',
        'pepipost_account_id' => 'sometimes|integer|exists:pepipost_accounts,id',
        'date_from' => 'sometimes|date',
        'date_to' => 'sometimes|date|after_or_equal:date_from',
        'status' => 'sometimes|string|in:normal,warning,all',
        'limit' => 'sometimes|integer|min:1|max:100',
        'page' => 'sometimes|integer|min:1'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $result = $this->generateDiscrepancyReport($request->all());
        
        return response()->json([
            'success' => true,
            'data' => $result
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to generate discrepancy report: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Generate discrepancy report dengan berbagai filter
 */
private function generateDiscrepancyReport($filters)
{
    $dateFrom = $filters['date_from'] ?? now()->subDays(7)->format('Y-m-d');
    $dateTo = $filters['date_to'] ?? now()->format('Y-m-d');
    $status = $filters['status'] ?? 'all';
    $limit = $filters['limit'] ?? 50;
    $page = $filters['page'] ?? 1;
    $offset = ($page - 1) * $limit;

    // Base query untuk quota usage
    $query = QuotaUsage::with(['crmUnit', 'pepipostAccount'])
        ->whereBetween('usage_date', [$dateFrom, $dateTo]);

    // Filter berdasarkan app_code jika ada
    if (isset($filters['app_code'])) {
        $crmUnit = CrmUnit::where('app_code', $filters['app_code'])->first();
        if ($crmUnit) {
            $query->where('crm_unit_id', $crmUnit->id);
        }
    }

    // Filter berdasarkan pepipost_account_id jika ada
    if (isset($filters['pepipost_account_id'])) {
        $query->where('pepipost_account_id', $filters['pepipost_account_id']);
    }

    // Filter berdasarkan status discrepancy
    if ($status !== 'all') {
        $query->where('discrepancy_status', $status);
    }

    // Get total count untuk pagination
    $totalCount = $query->count();

    // Get data dengan pagination
    $quotaUsages = $query->orderBy('usage_date', 'desc')
        ->orderBy('last_sync_at', 'desc')
        ->offset($offset)
        ->limit($limit)
        ->get();

    // Process data untuk report
    $reportData = [];
    $summary = [
        'total_records' => $totalCount,
        'warning_count' => 0,
        'normal_count' => 0,
        'total_daily_discrepancy' => 0,
        'total_monthly_discrepancy' => 0,
        'date_range' => [
            'from' => $dateFrom,
            'to' => $dateTo
        ]
    ];

    foreach ($quotaUsages as $usage) {
        $discrepancyDetails = $usage->discrepancy_details ?? [];
        
        $reportItem = [
            'id' => $usage->id,
            'usage_date' => $usage->usage_date,
            'crm_unit' => [
                'id' => $usage->crmUnit->id,
                'app_code' => $usage->crmUnit->app_code,
                'name' => $usage->crmUnit->name
            ],
            'pepipost_account' => [
                'id' => $usage->pepipostAccount->id,
                'name' => $usage->pepipostAccount->name
            ],
            'quota_data' => [
                'daily_used' => $usage->daily_used,
                'monthly_used' => $usage->monthly_used
            ],
            'sync_info' => [
                'sync_type' => $usage->sync_type,
                'last_sync_at' => $usage->last_sync_at,
                'discrepancy_status' => $usage->discrepancy_status
            ],
            'discrepancy_details' => $discrepancyDetails,
            'has_warning' => $usage->discrepancy_status === 'warning'
        ];

        // Hitung summary
        if ($usage->discrepancy_status === 'warning') {
            $summary['warning_count']++;
            
            // Hitung total discrepancy
            foreach ($discrepancyDetails as $detail) {
                if ($detail['type'] === 'daily_discrepancy') {
                    $summary['total_daily_discrepancy'] += $detail['difference'];
                } elseif ($detail['type'] === 'monthly_discrepancy') {
                    $summary['total_monthly_discrepancy'] += $detail['difference'];
                }
            }
        } else {
            $summary['normal_count']++;
        }

        $reportData[] = $reportItem;
    }

    // Pagination info
    $pagination = [
        'current_page' => $page,
        'per_page' => $limit,
        'total' => $totalCount,
        'last_page' => ceil($totalCount / $limit),
        'from' => $offset + 1,
        'to' => min($offset + $limit, $totalCount)
    ];

    return [
        'summary' => $summary,
        'data' => $reportData,
        'pagination' => $pagination,
        'filters_applied' => array_filter($filters)
    ];
}

/**
 * GET /api/quota/discrepancy/summary
 * Summary discrepancy untuk dashboard
 */
public function getDiscrepancySummary(Request $request)
{
    $validator = Validator::make($request->all(), [
        'days' => 'sometimes|integer|min:1|max:30'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    $days = $request->days ?? 7;
    $endDate = now()->format('Y-m-d');
    $startDate = now()->subDays($days)->format('Y-m-d');
    
    // Jika request mencakup periode billing yang berbeda, sesuaikan
    $currentBillingPeriod = BillingCycleService::getBillingPeriod();
    if ($startDate < $currentBillingPeriod['start_date']) {
        $startDate = $currentBillingPeriod['start_date'];
    }
    
    try {
        // PERBAIKAN: Gunakan variabel yang benar
        $statusSummary = QuotaUsage::whereBetween('usage_date', [$startDate, $endDate])  // ✅ Use correct variables
            ->selectRaw('discrepancy_status, COUNT(*) as count')
            ->groupBy('discrepancy_status')
            ->get()
            ->keyBy('discrepancy_status');

        // PERBAIKAN: Update semua query lainnya juga
        $unitSummary = QuotaUsage::with('crmUnit')
            ->whereBetween('usage_date', [$startDate, $endDate])  // ✅ Use correct variables
            ->where('discrepancy_status', 'warning')
            ->selectRaw('crm_unit_id, COUNT(*) as warning_count')
            ->groupBy('crm_unit_id')
            ->get()
            ->map(function ($item) {
                return [
                    'unit_code' => $item->crmUnit->app_code,
                    'unit_name' => $item->crmUnit->name,
                    'warning_count' => $item->warning_count
                ];
            });

        // PERBAIKAN: Update daily trend query
        $dailyTrend = QuotaUsage::whereBetween('usage_date', [$startDate, $endDate])  // ✅ Use correct variables
            ->selectRaw('usage_date, discrepancy_status, COUNT(*) as count')
            ->groupBy('usage_date', 'discrepancy_status')
            ->orderBy('usage_date')
            ->get()
            ->groupBy('usage_date')
            ->map(function ($dayData, $date) {
                $warningCount = $dayData->where('discrepancy_status', 'warning')->sum('count');
                $normalCount = $dayData->where('discrepancy_status', 'normal')->sum('count');
                
                return [
                    'date' => $date,
                    'warning_count' => $warningCount,
                    'normal_count' => $normalCount,
                    'total_count' => $warningCount + $normalCount
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'days' => $days,
                    'from' => $startDate,  // ✅ Use correct variable
                    'to' => $endDate       // ✅ Use correct variable
                ],
                'status_summary' => [
                    'warning' => $statusSummary->get('warning')->count ?? 0,
                    'normal' => $statusSummary->get('normal')->count ?? 0,
                    'total' => $statusSummary->sum('count')
                ],
                'unit_summary' => $unitSummary,
                'daily_trend' => $dailyTrend
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to generate discrepancy summary: ' . $e->getMessage()
        ], 500);
    }
}
}