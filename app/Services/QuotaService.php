<?php

namespace App\Services;

use App\Models\CrmUnit;
use App\Models\QuotaUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\BillingCycleService;

class QuotaService
{
    public function getQuotaStatus($appCode, $date)
    {
        $crmUnit = \App\Models\CrmUnit::with('pepipostAccount')->where('app_code', $appCode)->first();
        
        if (!$crmUnit) {
            throw new \Exception('CRM Unit not found');
        }

        $pepipostAccount = $crmUnit->pepipostAccount;
        $carbonDate = Carbon::parse($date);
        
        // ✅ PERBAIKAN: Gunakan tanggal untuk billing period
        $monthlyData = $this->getMonthlyQuotaData($pepipostAccount, $crmUnit, $date);
        
        // Proyeksi penggunaan
        $projection = $this->getUsageProjection($pepipostAccount, $carbonDate);
        
        return [
            'date' => $date,
            'daily' => $dailyData,
            'monthly' => $monthlyData,
            'projection' => $projection,
            'recommendations' => $this->getRecommendations($dailyData, $monthlyData)
        ];
    }

    public function updateQuotaUsage($pepipostAccountId, $crmUnitId, $date, $emailCount, $type = 'campaign')
    {
        $carbonDate = Carbon::parse($date);
        
        DB::transaction(function () use ($pepipostAccountId, $crmUnitId, $carbonDate, $emailCount, $type) {
            $quotaUsage = QuotaUsage::firstOrCreate(
                [
                    'pepipost_account_id' => $pepipostAccountId,
                    'crm_unit_id' => $crmUnitId,
                    'usage_date' => $carbonDate->format('Y-m-d')
                ],
                [
                    'daily_used' => 0,
                    'monthly_used' => 0,
                    'mandatory_used' => 0,
                    'breakdown' => []
                ]
            );

            $breakdown = $quotaUsage->breakdown ?? [];
            $breakdown[] = [
                'timestamp' => now()->toISOString(),
                'type' => $type,
                'count' => $emailCount
            ];

            $quotaUsage->increment('daily_used', $emailCount);
            $quotaUsage->increment('monthly_used', $emailCount);
            
            if ($type === 'mandatory') {
                $quotaUsage->increment('mandatory_used', $emailCount);
            }
            
            $quotaUsage->update(['breakdown' => $breakdown]);
        });
    }

    private function getDailyQuotaData($pepipostAccount, $crmUnit, $date)
    {
        $totalUsed = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('usage_date', $date)
            ->sum('daily_used');
            
        $unitUsed = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('crm_unit_id', $crmUnit->id)
            ->where('usage_date', $date)
            ->sum('daily_used');
            
        $mandatoryTotal = \App\Models\CrmUnit::where('pepipost_account_id', $pepipostAccount->id)
            ->where('is_active', true)
            ->sum('mandatory_daily_quota');

        return [
            'group_quota' => $pepipostAccount->daily_quota,
            'group_used' => $totalUsed,
            'group_available' => $pepipostAccount->daily_quota - $totalUsed - $mandatoryTotal,
            'unit_quota' => $crmUnit->daily_quota,
            'unit_used' => $unitUsed,
            'unit_available' => $crmUnit->daily_quota - $unitUsed,
            'mandatory_reserved' => $mandatoryTotal,
            'usage_percentage' => $pepipostAccount->daily_quota > 0 ? 
                round(($totalUsed / $pepipostAccount->daily_quota) * 100, 2) : 0
        ];
    }

    private function getMonthlyQuotaData($pepipostAccount, $crmUnit, $date = null)
    {
        $billingPeriod = BillingCycleService::getBillingPeriod($date);
        
        $totalUsed = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->whereBetween('usage_date', [$billingPeriod['start_date'], $billingPeriod['end_date']])
            ->sum('monthly_used');
            
        $unitUsed = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->where('crm_unit_id', $crmUnit->id)
            ->whereBetween('usage_date', [$billingPeriod['start_date'], $billingPeriod['end_date']])
            ->sum('monthly_used');
    
        return [
            'billing_period' => $billingPeriod,
            'group_quota' => $pepipostAccount->monthly_quota,
            'group_used' => $totalUsed,
            'group_available' => $pepipostAccount->monthly_quota - $totalUsed,
            'unit_quota' => $crmUnit->monthly_quota,
            'unit_used' => $unitUsed,
            'unit_available' => $crmUnit->monthly_quota - $unitUsed,
            'usage_percentage' => $pepipostAccount->monthly_quota > 0 ? 
                round(($totalUsed / $pepipostAccount->monthly_quota) * 100, 2) : 0
        ];
    }

    private function getUsageProjection($pepipostAccount, $date)
    {
        $daysInMonth = $date->daysInMonth;
        $dayOfMonth = $date->day;
        $remainingDays = $daysInMonth - $dayOfMonth + 1;
        
        // ✅ PERBAIKAN: Gunakan billing period
        $billingPeriod = BillingCycleService::getBillingPeriod($date);
        $monthlyUsed = QuotaUsage::where('pepipost_account_id', $pepipostAccount->id)
            ->whereBetween('usage_date', [$billingPeriod['start_date'], $billingPeriod['end_date']])
            ->sum('monthly_used');
            
        $averageDailyUsage = $dayOfMonth > 1 ? $monthlyUsed / ($dayOfMonth - 1) : 0;
        $projectedMonthlyUsage = $monthlyUsed + ($averageDailyUsage * $remainingDays);
        
        return [
            'average_daily_usage' => round($averageDailyUsage, 0),
            'projected_monthly_usage' => round($projectedMonthlyUsage, 0),
            'projected_percentage' => $pepipostAccount->monthly_quota > 0 ? 
                round(($projectedMonthlyUsage / $pepipostAccount->monthly_quota) * 100, 2) : 0,
            'remaining_days' => $remainingDays
        ];
    }

    private function getRecommendations($dailyData, $monthlyData)
    {
        $recommendations = [];
        
        if ($dailyData['usage_percentage'] > 80) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Daily quota usage is above 80%. Consider rescheduling non-urgent campaigns.'
            ];
        }
        
        if ($monthlyData['usage_percentage'] > 90) {
            $recommendations[] = [
                'type' => 'critical',
                'message' => 'Monthly quota usage is above 90%. Immediate action required.'
            ];
        }
        
        if ($dailyData['group_available'] < 1000) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'Low daily quota remaining. Plan campaigns for tomorrow.'
            ];
        }
        
        return $recommendations;
    }
}