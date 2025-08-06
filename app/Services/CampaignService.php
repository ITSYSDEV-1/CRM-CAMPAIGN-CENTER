<?php

namespace App\Services;

use App\Models\CrmUnit;
use App\Models\Campaign;
use App\Models\PepipostAccount;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CampaignService
{
    protected $quotaService;
    protected $quotaManager;

    public function __construct(QuotaService $quotaService, QuotaManager $quotaManager)
    {
        $this->quotaService = $quotaService;
        $this->quotaManager = $quotaManager;
    }

    public function getScheduleOverview($appCode, $date)
    {
        $crmUnit = CrmUnit::with('pepipostAccount')->where('app_code', $appCode)->first();
        
        if (!$crmUnit) {
            throw new \Exception('CRM Unit not found');
        }

        $pepipostAccount = $crmUnit->pepipostAccount;
        
        // Gunakan QuotaManager untuk mendapatkan data overview
        $quotaData = $this->quotaManager->getOverviewData($crmUnit, $date);
        
        // Kampanye yang sudah dijadwalkan untuk tanggal tersebut
        $scheduledCampaigns = Campaign::with('crmUnit')
            ->where('pepipost_account_id', $pepipostAccount->id)
            ->where('scheduled_date', $date)
            ->whereIn('status', ['pending', 'approved'])
            ->get()
            ->map(function ($campaign) {
                return [
                    'id' => $campaign->id,
                    'unit' => $campaign->crmUnit->app_code,
                    'email_count' => $campaign->email_count,
                    'status' => $campaign->status,
                    'type' => $campaign->campaign_type,
                    'subject' => $campaign->subject
                ];
            });

        // Unit lain dalam grup yang sama
        $groupUnits = CrmUnit::where('pepipost_account_id', $pepipostAccount->id)
            ->where('is_active', true)
            ->get(['app_code', 'name', 'daily_quota', 'mandatory_daily_quota']);

        // Saran tanggal alternatif
        $alternativeDates = $this->getAlternativeDates($pepipostAccount, $date, 1, $crmUnit);

        return array_merge($quotaData, [
            'date' => $date,
            'scheduled_campaigns' => $scheduledCampaigns,
            'group_units' => $groupUnits,
            'alternative_dates' => $alternativeDates,
            'can_book' => $this->quotaManager->canBookCampaign($crmUnit, $date),
            'quota_mode' => $this->quotaManager->isEqualQuotaEnabled() ? 'equal' : 'group',
            'sync_status' => [
                'can_sync_today' => $crmUnit->canSyncToday(),
                'sync_count_today' => $this->getTodaySyncCount($crmUnit)
            ]
        ]);
    }

    // Method validateQuotaWithReservation diganti dengan QuotaManager
    private function validateQuotaWithReservation($crmUnit, $date, $emailCount)
    {
        return $this->quotaManager->validateCampaignRequest($crmUnit, $date, $emailCount);
    }

    // Method canBookCampaign diganti dengan QuotaManager
    private function canBookCampaign($crmUnit, $date)
    {
        return $this->quotaManager->canBookCampaign($crmUnit, $date);
    }

    public function requestCampaign($data)
    {
        $crmUnit = CrmUnit::with('pepipostAccount')->where('app_code', $data['app_code'])->first();
        
        if (!$crmUnit || !$crmUnit->is_active) {
            return [
                'success' => false,
                'message' => 'CRM Unit not found or inactive'
            ];
        }

        try {
            DB::beginTransaction();
            
            // PERBAIKAN: Proses dengan locking untuk mencegah race condition
            $result = $this->processRequestWithLocking($crmUnit, $data);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // Method baru untuk menangani first request wins dengan locking
    private function processRequestWithLocking($crmUnit, $data)
    {
        $pepipostAccount = $crmUnit->pepipostAccount;
        $date = $data['scheduled_date'];
        $emailCount = $data['email_count'];
        
        // PERBAIKAN: Gunakan SELECT FOR UPDATE instead of LOCK TABLES
        // Cek apakah ada pending requests untuk tanggal yang sama dengan locking
        $conflictingRequests = Campaign::where('pepipost_account_id', $pepipostAccount->id)
            ->where('scheduled_date', $date)
            ->where('status', 'pending')
            ->orderBy('requested_at', 'asc')
            ->lockForUpdate() // Gunakan row-level locking
            ->get();
            
        // Validasi kuota dengan reservasi yang sudah ada
        $quotaCheck = $this->validateQuotaWithReservation($crmUnit, $date, $emailCount);
        
        // PERBAIKAN: Handle auto-booking ketika group quota 0
        if ($quotaCheck['valid'] === 'auto_book') {
            return $this->handleAutoBookingOnly($crmUnit, $pepipostAccount, $data, $date, $emailCount);
        }
        
        if ($quotaCheck['valid'] === false) {
            // Berikan saran tanggal berurutan
            $suggestions = $this->getSequentialDateSuggestions($pepipostAccount, $date, $emailCount);
            
            return [
                'success' => false,
                'message' => $quotaCheck['message'],
                'suggestions' => $suggestions
            ];
        }
        
        // Handle partial approval dengan auto-booking
        if ($quotaCheck['valid'] === 'partial') {
            return $this->handlePartialApprovalWithAutoBooking(
                $crmUnit, 
                $pepipostAccount, 
                $data, 
                $quotaCheck, 
                $date, 
                $emailCount
            );
        }
        
        // Handle full approval
        $campaign = Campaign::create([
            'crm_unit_id' => $crmUnit->id,
            'pepipost_account_id' => $crmUnit->pepipost_account_id,
            'scheduled_date' => $date,
            'email_count' => $emailCount,
            'campaign_type' => $data['campaign_type'] ?? 'regular',
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'approved', // LANGSUNG APPROVE = LANGSUNG RESERVE
            'requested_at' => now(),
            'approved_at' => now(),
            'metadata' => [
                'quota_reserved' => true // Flag bahwa kuota sudah direservasi
            ]
        ]);
        
        return [
            'success' => true,
            'type' => 'full_approval',
            'message' => 'Campaign approved and quota RESERVED successfully',
            'data' => [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
                'scheduled_date' => $campaign->scheduled_date->format('Y-m-d'),
                'email_count' => $campaign->email_count,
                'quota_reserved' => true
            ]
        ];
    }

    // Method untuk auto-booking sequential dates pada partial approval
    private function handlePartialApprovalWithAutoBooking($crmUnit, $pepipostAccount, $data, $quotaCheck, $date, $emailCount)
    {
        $approvedCount = $quotaCheck['approved_count'];
        $remainingCount = $quotaCheck['remaining_count'];
        
        // Buat campaign untuk bagian yang disetujui (LANGSUNG RESERVE KUOTA)
        $mainCampaign = Campaign::create([
            'crm_unit_id' => $crmUnit->id,
            'pepipost_account_id' => $crmUnit->pepipost_account_id,
            'scheduled_date' => $date,
            'email_count' => $approvedCount,
            'campaign_type' => $data['campaign_type'] ?? 'regular',
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'approved',
            'requested_at' => now(),
            'approved_at' => now(),
            'metadata' => [
                'original_request' => $emailCount,
                'partial_approval' => true,
                'main_campaign' => true,
                'quota_reserved' => true
            ]
        ]);
        
        // AUTO-BOOKING: Reservasi otomatis untuk sisa email pada tanggal berurutan
        $autoBookedCampaigns = [];
        $remainingToBook = $remainingCount;
        $startDate = Carbon::parse($date)->addDay();
        
        for ($i = 0; $i < 14 && $remainingToBook > 0; $i++) {
            $checkDate = $startDate->copy()->addDays($i)->format('Y-m-d');
            
            // PERBAIKAN: Gunakan QuotaManager untuk mendapatkan quota yang sesuai dengan mode
            if ($this->quotaManager->isEqualQuotaEnabled()) {
                $availableQuota = $this->quotaManager->getAvailableQuota($crmUnit, $checkDate);
            } else {
                $availableQuota = $pepipostAccount->getAvailableDailyQuota($checkDate);
            }
            
            if ($availableQuota > 0) {
                $bookingCount = min($remainingToBook, $availableQuota);
                
                // Buat campaign otomatis untuk tanggal ini
                $autoCampaign = Campaign::create([
                    'crm_unit_id' => $crmUnit->id,
                    'pepipost_account_id' => $crmUnit->pepipost_account_id,
                    'scheduled_date' => $checkDate,
                    'email_count' => $bookingCount,
                    'campaign_type' => $data['campaign_type'] ?? 'regular',
                    'subject' => ($data['subject'] ?? '') . ' (Auto-booked)',
                    'description' => ($data['description'] ?? '') . ' - Auto-booked continuation',
                    'status' => 'approved',
                    'requested_at' => now(),
                    'approved_at' => now(),
                    'metadata' => [
                        'original_request' => $emailCount,
                        'auto_booked' => true,
                        'main_campaign_id' => $mainCampaign->id,
                        'sequence_order' => count($autoBookedCampaigns) + 1,
                        'quota_reserved' => true
                    ]
                ]);
                
                $autoBookedCampaigns[] = [
                    'campaign_id' => $autoCampaign->id,
                    'date' => $checkDate,
                    'email_count' => $bookingCount,
                    'day_name' => Carbon::parse($checkDate)->format('l')
                ];
                
                $remainingToBook -= $bookingCount;
            }
        }
        
        return [
            'success' => true,
            'type' => 'partial_approval_with_auto_booking',
            'message' => "Campaign partially approved with auto-booking: {$approvedCount} emails on {$date}, " . ($remainingCount - $remainingToBook) . " emails auto-booked on subsequent dates",
            'data' => [
                'main_campaign' => [
                    'campaign_id' => $mainCampaign->id,
                    'scheduled_date' => $mainCampaign->scheduled_date->format('Y-m-d'),
                    'email_count' => $approvedCount,
                    'status' => 'approved'
                ],
                'auto_booked_campaigns' => $autoBookedCampaigns,
                'total_requested' => $emailCount,
                'total_approved' => $emailCount - $remainingToBook,
                'remaining_unbooked' => $remainingToBook,
                'quota_reserved' => true
            ]
        ];
    }

    // Method baru untuk saran tanggal berurutan
    private function getSequentialDateSuggestions($pepipostAccount, $currentDate, $emailCount)
    {
        $alternatives = [];
        $startDate = Carbon::parse($currentDate)->addDay();
        
        // Cari tanggal berurutan yang tersedia
        for ($i = 0; $i < 14; $i++) {
            $checkDate = $startDate->copy()->addDays($i)->format('Y-m-d');
            $available = $pepipostAccount->getAvailableDailyQuota($checkDate);
            
            if ($available > 0) {
                $alternatives[] = [
                    'date' => $checkDate,
                    'available_quota' => $available,
                    'day_name' => Carbon::parse($checkDate)->format('l'),
                    'can_reserve' => true
                ];
                
                // Berikan maksimal 5 saran berurutan
                if (count($alternatives) >= 5) break;
            }
        }
        
        return [
            'type' => 'sequential_dates',
            'message' => 'Suggested consecutive dates for remaining emails',
            'dates' => $alternatives,
            'note' => 'Dates are suggested in sequential order starting from the day after your requested date'
        ];
    }

    public function syncData($appCode, $syncType = 'manual')
    {
        $crmUnit = CrmUnit::where('app_code', $appCode)->first();
        
        if (!$crmUnit->canSyncToday()) {
            return [
                'success' => false,
                'message' => 'Daily sync limit exceeded',
                'remaining_syncs' => 0
            ];
        }

        try {
            DB::beginTransaction();

            // Log sync activity
            $today = now()->format('Y-m-d');
            $syncLog = SyncLog::firstOrCreate(
                [
                    'crm_unit_id' => $crmUnit->id,
                    'sync_date' => $today
                ],
                [
                    'sync_count' => 0,
                    'sync_type' => $syncType,
                    'sync_data' => []
                ]
            );

            $syncLog->increment('sync_count');
            $syncLog->update([
                'sync_data' => array_merge($syncLog->sync_data ?? [], [
                    'last_sync' => now()->toISOString(),
                    'type' => $syncType
                ])
            ]);

            // Data untuk disinkronkan
            $syncData = $this->prepareSyncData($crmUnit);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Sync completed successfully',
                'data' => $syncData,
                'remaining_syncs' => $crmUnit->max_sync_per_day - $syncLog->sync_count
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function getAlternativeDates($pepipostAccount, $currentDate, $emailCount = 1, $crmUnit = null)
    {
        $alternatives = [];
        $startDate = Carbon::parse($currentDate)->addDay();
        
        for ($i = 0; $i < 14; $i++) {
            $checkDate = $startDate->copy()->addDays($i)->format('Y-m-d');
            
            // PERBAIKAN: Gunakan QuotaManager untuk mendapatkan quota yang sesuai dengan mode
            if ($crmUnit && $this->quotaManager->isEqualQuotaEnabled()) {
                // Jika equal quota mode, gunakan quota per unit
                $available = $this->quotaManager->getAvailableQuota($crmUnit, $checkDate);
            } else {
                // Jika group quota mode, gunakan group quota
                $available = $pepipostAccount->getAvailableDailyQuota($checkDate);
            }
            
            // Tampilkan semua tanggal yang memiliki kuota tersedia (> 0)
            if ($available > 0) {
                $alternatives[] = [
                    'date' => $checkDate,
                    'available_quota' => $available,
                    'day_name' => Carbon::parse($checkDate)->format('l')
                ];
                
                if (count($alternatives) >= 5) break;
            }
        }
        
        return $alternatives;
    }

    private function prepareSyncData($crmUnit)
    {
        $today = now()->format('Y-m-d');
        $nextWeek = now()->addWeek()->format('Y-m-d');
        
        return [
            'unit_info' => [
                'app_code' => $crmUnit->app_code,
                'name' => $crmUnit->name,
                'daily_quota' => $crmUnit->daily_quota,
                'monthly_quota' => $crmUnit->monthly_quota
            ],
            'upcoming_campaigns' => $crmUnit->campaigns()
                ->whereBetween('scheduled_date', [$today, $nextWeek])
                ->whereIn('status', ['pending', 'approved'])
                ->get(['id', 'scheduled_date', 'email_count', 'status', 'subject']),
            'quota_status' => $this->quotaService->getQuotaStatus($crmUnit->app_code, $today),
            'sync_timestamp' => now()->toISOString()
        ];
    }
    private function getTodaySyncCount($crmUnit)
    {
        $today = now()->format('Y-m-d');
        return $crmUnit->syncLogs()
            ->where('sync_date', $today)
            ->sum('sync_count');
    }

    private function handleAutoBookingOnly($crmUnit, $pepipostAccount, $data, $originalDate, $emailCount)
    {
        $autoBookedCampaigns = [];
        $remainingToBook = $emailCount;
        $startDate = Carbon::parse($originalDate)->addDay();
        
        for ($i = 0; $i < 14 && $remainingToBook > 0; $i++) {
            $checkDate = $startDate->copy()->addDays($i)->format('Y-m-d');
            
            // PERBAIKAN: Gunakan QuotaManager untuk mendapatkan quota yang sesuai dengan mode
            if ($this->quotaManager->isEqualQuotaEnabled()) {
                $availableQuota = $this->quotaManager->getAvailableQuota($crmUnit, $checkDate);
            } else {
                $availableQuota = $pepipostAccount->getAvailableDailyQuota($checkDate);
            }
            
            if ($availableQuota > 0) {
                $bookingCount = min($remainingToBook, $availableQuota);
                
                $autoCampaign = Campaign::create([
                    'crm_unit_id' => $crmUnit->id,
                    'pepipost_account_id' => $crmUnit->pepipost_account_id,
                    'scheduled_date' => $checkDate,
                    'email_count' => $bookingCount,
                    'campaign_type' => $data['campaign_type'] ?? 'regular',
                    'subject' => ($data['subject'] ?? null) ? ($data['subject'] . ' (Auto-booked)') : null,
                    'description' => ($data['description'] ?? null) ? ($data['description'] . ' - Auto-booked due to no quota on ' . $originalDate) : null,
                    'status' => 'approved',
                    'requested_at' => now(),
                    'approved_at' => now(),
                    'metadata' => [
                        'original_request_date' => $originalDate,
                        'original_request' => $emailCount,
                        'auto_booked' => true,
                        'full_auto_booking' => true,
                        'sequence_order' => count($autoBookedCampaigns) + 1,
                        'quota_reserved' => true
                    ]
                ]);
                
                $autoBookedCampaigns[] = [
                    'campaign_id' => $autoCampaign->id,
                    'date' => $checkDate,
                    'email_count' => $bookingCount,
                    'day_name' => Carbon::parse($checkDate)->format('l')
                ];
                
                $remainingToBook -= $bookingCount;
            }
        }
        
        return [
            'success' => true,
            'type' => 'full_auto_booking',
            'message' => "No quota available for {$originalDate}. Auto-booked " . ($emailCount - $remainingToBook) . " emails on subsequent dates",
            'data' => [
                'original_date' => $originalDate,
                'auto_booked_campaigns' => $autoBookedCampaigns,
                'total_requested' => $emailCount,
                'total_auto_booked' => $emailCount - $remainingToBook,
                'remaining_unbooked' => $remainingToBook,
                'quota_reserved' => true
            ]
        ];
    }
    
    public function markCampaignAsSent($campaignId, $appCode, $actualEmailsSent = null)
    {
        try {
            DB::beginTransaction();
            
            $campaign = Campaign::findOrFail($campaignId);
            $crmUnit = CrmUnit::where('app_code', $appCode)->first();
            
            if (!$crmUnit || $campaign->crm_unit_id !== $crmUnit->id) {
                throw new \Exception('Campaign not found or access denied');
            }
            
            if ($campaign->status !== 'approved') {
                throw new \Exception('Only approved campaigns can be marked as sent');
            }
            
            $campaign->update([
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => array_merge($campaign->metadata ?? [], [
                    'sent_at' => now()->toISOString(),
                    'actual_emails_sent' => $actualEmailsSent ?? $campaign->email_count,
                    'quota_consumed' => true
                ])
            ]);
            
            // Update quota usage jika actualEmailsSent disediakan
            if ($actualEmailsSent !== null) {
                $this->quotaService->updateQuotaUsage(
                    $campaign->pepipost_account_id,
                    $campaign->crm_unit_id,
                    $campaign->scheduled_date->format('Y-m-d'),
                    $actualEmailsSent,
                    $campaign->campaign_type
                );
            }
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'Campaign marked as sent successfully',
                'data' => [
                    'campaign_id' => $campaign->id,
                    'status' => 'sent',
                    'sent_at' => $campaign->sent_at->toISOString(),
                    'actual_emails_sent' => $actualEmailsSent ?? $campaign->email_count
                ]
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    /**
     * Get schedule overview for date range
     */
    public function getScheduleOverviewRange($appCode, $startDate, $endDate)
    {
        $crmUnit = CrmUnit::with('pepipostAccount')->where('app_code', $appCode)->first();
        
        if (!$crmUnit) {
            throw new \Exception('CRM Unit not found');
        }

        $pepipostAccount = $crmUnit->pepipostAccount;
        $dateRange = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Validasi maksimal 31 hari
        if ($current->diffInDays($end) > 31) {
            throw new \Exception('Date range cannot exceed 31 days');
        }

        // Generate data untuk setiap tanggal dalam range
        while ($current <= $end) {
            $date = $current->format('Y-m-d');
            
            // Get scheduled campaigns for this date
            $scheduledCampaigns = Campaign::with('crmUnit')
                ->where('pepipost_account_id', $pepipostAccount->id)
                ->where('scheduled_date', $date)
                ->whereIn('status', ['pending', 'approved'])
                ->get()
                ->map(function ($campaign) {
                    return [
                        'id' => $campaign->id,
                        'unit' => $campaign->crmUnit->app_code,
                        'email_count' => $campaign->email_count,
                        'status' => $campaign->status,
                        'type' => $campaign->campaign_type,
                        'subject' => $campaign->subject
                    ];
                });

            $availableQuota = $pepipostAccount->getAvailableDailyQuota($date);
            $totalScheduled = $scheduledCampaigns->sum('email_count');
            
            $dateRange[] = [
                'date' => $date,
                'day_name' => $current->format('l'),
                'day_short' => $current->format('D'),
                'quota_info' => [
                    'daily_quota' => $pepipostAccount->daily_quota,
                    'available_quota' => $availableQuota,
                    'used_quota' => $pepipostAccount->daily_quota - $availableQuota,
                    'scheduled_count' => $totalScheduled,
                    'utilization_rate' => $pepipostAccount->daily_quota > 0 
                        ? round((($pepipostAccount->daily_quota - $availableQuota) / $pepipostAccount->daily_quota) * 100, 2) 
                        : 0
                ],
                'scheduled_campaigns' => $scheduledCampaigns,
                'can_book' => $availableQuota > 0,
                'status' => $this->getDateStatus($availableQuota, $pepipostAccount->daily_quota)
            ];
            
            $current->addDay();
        }

        // Calculate summary statistics
        $totalDays = count($dateRange);
        $totalQuotaCapacity = $pepipostAccount->daily_quota * $totalDays;
        $totalAvailableQuota = collect($dateRange)->sum('quota_info.available_quota');
        $totalUsedQuota = collect($dateRange)->sum('quota_info.used_quota');
        $totalScheduledEmails = collect($dateRange)->sum('quota_info.scheduled_count');
        $availableDays = collect($dateRange)->where('can_book', true)->count();
        $fullyBookedDays = collect($dateRange)->where('quota_info.available_quota', 0)->count();

        return [
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_days' => $totalDays
            ],
            'summary' => [
                'quota_capacity' => $totalQuotaCapacity,
                'total_available' => $totalAvailableQuota,
                'total_used' => $totalUsedQuota,
                'total_scheduled' => $totalScheduledEmails,
                'overall_utilization' => $totalQuotaCapacity > 0 
                    ? round(($totalUsedQuota / $totalQuotaCapacity) * 100, 2) 
                    : 0,
                'available_days' => $availableDays,
                'fully_booked_days' => $fullyBookedDays,
                'booking_rate' => $totalDays > 0 
                    ? round((($totalDays - $availableDays) / $totalDays) * 100, 2) 
                    : 0
            ],
            'account_info' => [
                'account_name' => $pepipostAccount->name,
                'daily_quota' => $pepipostAccount->daily_quota,
                'monthly_quota' => $pepipostAccount->monthly_quota
            ],
            'unit_info' => [
                'unit_name' => $crmUnit->name,
                'app_code' => $crmUnit->app_code,
                'daily_quota' => $crmUnit->daily_quota,
                'mandatory_daily' => $crmUnit->mandatory_daily_quota
            ],
            'daily_breakdown' => $dateRange,
            'group_units' => CrmUnit::where('pepipost_account_id', $pepipostAccount->id)
                ->where('is_active', true)
                ->get(['app_code', 'name', 'daily_quota', 'mandatory_daily_quota'])
        ];
    }

    /**
     * Get status label for a date based on quota availability
     */
    private function getDateStatus($availableQuota, $dailyQuota)
    {
        if ($availableQuota <= 0) {
            return 'fully_booked';
        } elseif ($availableQuota < ($dailyQuota * 0.2)) {
            return 'almost_full';
        } elseif ($availableQuota < ($dailyQuota * 0.5)) {
            return 'moderate';
        } else {
            return 'available';
        }
    }
    /**
     * Cancel campaign dan release kuota untuk kompetisi
     */
    public function cancelCampaign($campaignId, $appCode, $reason = null)
    {
        try {
            DB::beginTransaction();
            
            // Validasi campaign dan unit
            $campaign = Campaign::findOrFail($campaignId);
            $crmUnit = CrmUnit::where('app_code', $appCode)->first();
            
            if (!$crmUnit) {
                throw new \Exception('CRM Unit not found');
            }
            
            // Validasi ownership - campaign harus milik unit yang sama
            if ($campaign->crm_unit_id !== $crmUnit->id) {
                throw new \Exception('Campaign does not belong to this unit');
            }
            
            // Validasi hanya campaign yang belum dikirim yang bisa dibatalkan
            if ($campaign->status === 'sent') {
                throw new \Exception('Cannot cancel campaign that has already been sent');
            }
            
            if ($campaign->status === 'cancelled') {
                throw new \Exception('Campaign is already cancelled');
            }
            
            $originalDate = $campaign->scheduled_date->format('Y-m-d');
            $releasedQuota = $campaign->email_count;
            
            // Update status campaign menjadi cancelled
            $campaign->update([
                'status' => 'cancelled',
                'metadata' => array_merge($campaign->metadata ?? [], [
                    'cancelled_at' => now()->toISOString(),
                    'cancellation_reason' => $reason ?? 'Campaign cancelled by user',
                    'quota_released' => $releasedQuota,
                    'quota_reserved' => false // Kuota tidak lagi direservasi
                ])
            ]);
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'Campaign cancelled successfully. Quota is now available for other campaigns.',
                'data' => [
                    'cancelled_campaign' => [
                        'id' => $campaign->id,
                        'original_date' => $originalDate,
                        'released_quota' => $releasedQuota,
                        'status' => 'cancelled'
                    ],
                    'quota_status' => [
                        'date' => $originalDate,
                        'released_quota' => $releasedQuota,
                        'available_for_booking' => true,
                        'note' => 'This quota is now available for new campaign requests on the same date'
                    ]
                ]
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }   
}
