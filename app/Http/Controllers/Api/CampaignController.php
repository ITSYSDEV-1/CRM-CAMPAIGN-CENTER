<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmUnit;
use App\Models\Campaign;
use App\Models\PepipostAccount;
use App\Services\CampaignService;
use App\Services\QuotaService;
use App\Services\QuotaManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class CampaignController extends Controller
{
    protected $campaignService;
    protected $quotaService;
    protected $quotaManager;

    public function __construct(CampaignService $campaignService, QuotaService $quotaService, QuotaManager $quotaManager)
    {
        $this->campaignService = $campaignService;
        $this->quotaService = $quotaService;
        $this->quotaManager = $quotaManager;
    }

    /**
     * GET /api/schedule/overview
     * Melihat jadwal kampanye dan kuota harian
     */
    public function overview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|after_or_equal:today',
            'app_code' => 'required|string|exists:crm_units,app_code'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->date;
        $appCode = $request->app_code;

        // Langsung ambil data tanpa cache
        $data = $this->campaignService->getScheduleOverview($appCode, $date);
        
        // Tambahkan informasi mode quota
        $data['system_info'] = [
            'quota_mode' => $this->quotaManager->isEqualQuotaEnabled() ? 'equal_quota' : 'group_quota',
            'quota_mode_description' => $this->quotaManager->isEqualQuotaEnabled() 
                ? 'Equal quota distribution among units'
                : 'First request wins from group quota'
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * GET /api/schedule/overview/range
     * Melihat jadwal kampanye dan kuota harian untuk range tanggal
     */
    public function overviewRange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date|before_or_equal:' . now()->addMonth()->format('Y-m-d'),
            'app_code' => 'required|string|exists:crm_units,app_code'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $this->campaignService->getScheduleOverviewRange(
                $request->app_code,
                $request->start_date,
                $request->end_date
            );

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get schedule overview range',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * POST /api/schedule/request
     * Request slot kampanye baru
     */
    public function requestCampaign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string|exists:crm_units,app_code',
            'scheduled_date' => 'required|date|after_or_equal:today',
            'email_count' => 'required|integer|min:1|max:50000',
            'campaign_type' => 'sometimes|string|in:regular,urgent,promotional',
            'subject' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->campaignService->requestCampaign($request->all());
            
            // Clear cache setelah request baru
            $this->clearScheduleCache($request->app_code, $request->scheduled_date);
            
            return response()->json($result, $result['success'] ? 201 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/sync
     * Sinkronisasi data dengan CRM lokal
     */
    public function sync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string|exists:crm_units,app_code',
            'sync_type' => 'sometimes|string|in:manual,auto'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->campaignService->syncData($request->app_code, $request->sync_type ?? 'manual');
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/quota/status
     * Status kuota real-time
     */
    public function quotaStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string|exists:crm_units,app_code',
            'date' => 'sometimes|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->date ?? now()->format('Y-m-d');
        $quotaData = $this->quotaService->getQuotaStatus($request->app_code, $date);

        return response()->json([
            'success' => true,
            'data' => $quotaData
        ]);
    }

    /**
     * GET /api/campaigns/history
     * Riwayat kampanye
     */
    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string|exists:crm_units,app_code',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'status' => 'sometimes|string|in:pending,approved,rejected,sent,cancelled',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $crmUnit = \App\Models\CrmUnit::where('app_code', $request->app_code)->first();
        
        $query = $crmUnit->campaigns()->with(['pepipostAccount'])
            ->orderBy('scheduled_date', 'desc');

        if ($request->start_date) {
            $query->where('scheduled_date', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->where('scheduled_date', '<=', $request->end_date);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $campaigns = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $campaigns
        ]);
    }

    /**
     * DELETE /api/schedule/cancel/{campaignId}
     * Cancel campaign dan release kuota untuk kompetisi
     */
    public function cancelCampaign(Request $request, $campaignId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string|exists:crm_units,app_code',
            'reason' => 'sometimes|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->campaignService->cancelCampaign(
                $campaignId, 
                $request->app_code,
                $request->reason ?? 'Campaign cancelled by unit'
            );
            
            // Clear cache untuk tanggal yang terdampak
            if (isset($result['data']['cancelled_campaign']['original_date'])) {
                $this->clearScheduleCache(
                    $request->app_code, 
                    $result['data']['cancelled_campaign']['original_date']
                );
            }
            
            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel campaign',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Di method yang memanggil markCampaignAsSent, pastikan parameter konsisten:
    
    public function markAsSent(Request $request, $campaignId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_code' => 'required|string|exists:crm_units,app_code',
            'actual_emails_sent' => 'sometimes|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->campaignService->markCampaignAsSent(
                $campaignId, 
                $request->app_code,
                $request->actual_emails_sent
            );
            
            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark campaign as sent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function clearScheduleCache($appCode, $date)
    {
        Cache::forget("schedule_overview_{$appCode}_{$date}");
        // Clear cache untuk beberapa hari ke depan juga
        for ($i = 0; $i < 7; $i++) {
            $futureDate = now()->addDays($i)->format('Y-m-d');
            Cache::forget("schedule_overview_{$appCode}_{$futureDate}");
        }
    }
}