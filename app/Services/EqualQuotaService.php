<?php

namespace App\Services;

use App\Models\CrmUnit;
use App\Models\Campaign;
use App\Models\PepipostAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EqualQuotaService
{
    /**
     * Hitung available quota per unit berdasarkan equal distribution
     * FIXED: Quota tetap, tidak berkurang karena first-come-first-served
     */
    public function getUnitAvailableQuota($crmUnit, $date)
    {
        $pepipostAccount = $crmUnit->pepipostAccount;
        
        // Hitung total unit aktif dalam group
        $activeUnits = $pepipostAccount->crmUnits()
            ->where('is_active', true)
            ->get();
            
        $totalActiveUnits = $activeUnits->count();
        
        if ($totalActiveUnits === 0) {
            return 0;
        }
        
        // Hitung total mandatory quota semua unit
        $totalMandatoryQuota = $activeUnits->sum('mandatory_daily_quota');
        
        // Hitung available group quota setelah dikurangi mandatory
        $groupAvailableQuota = $pepipostAccount->daily_quota - $totalMandatoryQuota;
        
        // FIXED: Gunakan base quota distribution, bukan remaining quota
        // Setiap unit mendapat bagian tetap dari group quota
        $baseUnitShareFromGroup = floor($groupAvailableQuota / $totalActiveUnits);
        
        // Hitung quota yang sudah digunakan unit ini hari ini
        $unitUsedToday = $crmUnit->campaigns()
            ->where('scheduled_date', $date)
            ->active()
            ->sum('email_count');
            
        // Hitung unit quota (daily_quota - mandatory_daily_quota)
        $unitAvailableQuota = $crmUnit->daily_quota - $crmUnit->mandatory_daily_quota;
        
        // FIXED: Unit available quota adalah yang terkecil antara:
        // 1. Sisa unit quota (unit_quota - unit_used_today)
        // 2. Bagian tetap unit dari group quota (dikurangi yang sudah digunakan)
        $unitRemainingQuota = $unitAvailableQuota - $unitUsedToday;
        $unitRemainingFromGroup = $baseUnitShareFromGroup - $unitUsedToday;
        
        return max(0, min($unitRemainingQuota, $unitRemainingFromGroup));
    }
    
    /**
     * Validasi request campaign dengan equal quota
     */
    public function validateCampaignRequest($crmUnit, $date, $emailCount)
    {
        $unitAvailableQuota = $this->getUnitAvailableQuota($crmUnit, $date);
        
        if ($emailCount <= $unitAvailableQuota) {
            return [
                'valid' => true,
                'available_quota' => $unitAvailableQuota
            ];
        }
        
        if ($unitAvailableQuota > 0) {
            return [
                'valid' => 'partial',
                'approved_count' => $unitAvailableQuota,
                'remaining_count' => $emailCount - $unitAvailableQuota,
                'message' => "Partial approval: {$unitAvailableQuota} emails can be reserved for {$date} (unit equal quota limit)"
            ];
        }
        
        return [
            'valid' => false,
            'message' => 'No quota available for this unit on the requested date',
            'available_quota' => 0
        ];
    }
    
    /**
     * Get overview data dengan equal quota calculation
     */
    public function getEqualQuotaOverview($crmUnit, $date)
    {
        $pepipostAccount = $crmUnit->pepipostAccount;
        
        // Data kuota grup
        $groupQuota = [
            'account_name' => $pepipostAccount->name,
            'daily_quota' => $pepipostAccount->daily_quota,
            'monthly_quota' => $pepipostAccount->monthly_quota,
            'available_daily' => $pepipostAccount->getAvailableDailyQuota($date),
            'available_monthly' => $pepipostAccount->getAvailableMonthlyQuota($date)
        ];
        
        // Data kuota unit dengan equal distribution
        $unitAvailableQuota = $this->getUnitAvailableQuota($crmUnit, $date);
        $unitUsedToday = $crmUnit->campaigns()
            ->where('scheduled_date', $date)
            ->active()
            ->sum('email_count');
            
        $unitQuota = [
            'unit_name' => $crmUnit->name,
            'daily_quota' => $crmUnit->daily_quota,
            'monthly_quota' => $crmUnit->monthly_quota,
            'mandatory_daily' => $crmUnit->mandatory_daily_quota,
            'available_daily' => $unitAvailableQuota,
            'used_today' => $unitUsedToday,
            'equal_quota_enabled' => true
        ];
        
        return [
            'group_quota' => $groupQuota,
            'unit_quota' => $unitQuota,
            'quota_distribution' => $this->getQuotaDistribution($pepipostAccount, $date)
        ];
    }
    
    /**
     * Get distribusi quota antar unit
     */
    private function getQuotaDistribution($pepipostAccount, $date)
    {
        $activeUnits = $pepipostAccount->crmUnits()
            ->where('is_active', true)
            ->get();
            
        $distribution = [];
        
        foreach ($activeUnits as $unit) {
            $unitAvailable = $this->getUnitAvailableQuota($unit, $date);
            $unitUsed = $unit->campaigns()
                ->where('scheduled_date', $date)
                ->active()
                ->sum('email_count');
                
            $distribution[] = [
                'unit_code' => $unit->app_code,
                'unit_name' => $unit->name,
                'available_quota' => $unitAvailable,
                'used_quota' => $unitUsed,
                'unit_daily_quota' => $unit->daily_quota,
                'mandatory_quota' => $unit->mandatory_daily_quota
            ];
        }
        
        return $distribution;
    }

    /**
     * TAMBAHAN: Method untuk mendapatkan base quota setiap unit
     * Berguna untuk debugging dan monitoring
     */
    public function getBaseUnitQuota($crmUnit)
    {
        $pepipostAccount = $crmUnit->pepipostAccount;
        
        $activeUnits = $pepipostAccount->crmUnits()
            ->where('is_active', true)
            ->count();
            
        if ($activeUnits === 0) {
            return 0;
        }
        
        $totalMandatoryQuota = $pepipostAccount->crmUnits()
            ->where('is_active', true)
            ->sum('mandatory_daily_quota');
            
        $groupAvailableQuota = $pepipostAccount->daily_quota - $totalMandatoryQuota;
        
        return floor($groupAvailableQuota / $activeUnits);
    }
}