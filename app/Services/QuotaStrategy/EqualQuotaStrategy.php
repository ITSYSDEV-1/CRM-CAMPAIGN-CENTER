<?php

namespace App\Services\QuotaStrategy;

use App\Models\CrmUnit;
use App\Services\EqualQuotaService;

class EqualQuotaStrategy implements QuotaStrategyInterface
{
    protected $equalQuotaService;
    
    public function __construct(EqualQuotaService $equalQuotaService)
    {
        $this->equalQuotaService = $equalQuotaService;
    }
    
    public function getAvailableQuota($crmUnit, $date)
    {
        return $this->equalQuotaService->getUnitAvailableQuota($crmUnit, $date);
    }
    
    public function validateCampaignRequest($crmUnit, $date, $emailCount)
    {
        return $this->equalQuotaService->validateCampaignRequest($crmUnit, $date, $emailCount);
    }
    
    public function getOverviewData($crmUnit, $date)
    {
        return $this->equalQuotaService->getEqualQuotaOverview($crmUnit, $date);
    }
    
    public function canBookCampaign($crmUnit, $date)
    {
        return $this->getAvailableQuota($crmUnit, $date) > 0;
    }
}