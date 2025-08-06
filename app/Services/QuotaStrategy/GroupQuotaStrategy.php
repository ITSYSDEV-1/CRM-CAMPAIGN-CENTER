<?php

namespace App\Services\QuotaStrategy;

use App\Models\CrmUnit;

class GroupQuotaStrategy implements QuotaStrategyInterface
{
    public function getAvailableQuota($crmUnit, $date)
    {
        return $crmUnit->pepipostAccount->getAvailableDailyQuota($date);
    }
    
    public function validateCampaignRequest($crmUnit, $date, $emailCount)
    {
        $availableGroupQuota = $this->getAvailableQuota($crmUnit, $date);
        
        if ($availableGroupQuota <= 0) {
            return [
                'valid' => 'auto_book',
                'message' => 'No group quota available - proceeding with auto-booking'
            ];
        }
        
        if ($emailCount > $availableGroupQuota) {
            return [
                'valid' => 'partial',
                'approved_count' => $availableGroupQuota,
                'remaining_count' => $emailCount - $availableGroupQuota,
                'message' => "Partial approval: {$availableGroupQuota} emails can be reserved for {$date} (group quota limit)"
            ];
        }
        
        return ['valid' => true];
    }
    
    public function getOverviewData($crmUnit, $date)
    {
        $pepipostAccount = $crmUnit->pepipostAccount;
        
        return [
            'group_quota' => [
                'account_name' => $pepipostAccount->name,
                'daily_quota' => $pepipostAccount->daily_quota,
                'monthly_quota' => $pepipostAccount->monthly_quota,
                'available_daily' => $pepipostAccount->getAvailableDailyQuota($date),
                'available_monthly' => $pepipostAccount->getAvailableMonthlyQuota($date)
            ],
            'unit_quota' => [
                'unit_name' => $crmUnit->name,
                'daily_quota' => $crmUnit->daily_quota,
                'monthly_quota' => $crmUnit->monthly_quota,
                'mandatory_daily' => $crmUnit->mandatory_daily_quota,
                'available_daily' => 'unlimited'
            ]
        ];
    }
    
    public function canBookCampaign($crmUnit, $date)
    {
        return $this->getAvailableQuota($crmUnit, $date) > 0;
    }
}