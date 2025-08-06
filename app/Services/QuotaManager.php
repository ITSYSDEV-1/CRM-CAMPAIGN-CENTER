<?php

namespace App\Services;

use App\Services\QuotaStrategy\QuotaStrategyInterface;
use App\Services\QuotaStrategy\GroupQuotaStrategy;
use App\Services\QuotaStrategy\EqualQuotaStrategy;
use App\Services\EqualQuotaService;

class QuotaManager
{
    protected $strategy;
    
    public function __construct()
    {
        $this->strategy = $this->createStrategy();
    }
    
    private function createStrategy(): QuotaStrategyInterface
    {
        $enableEqualQuota = config('app.enable_equal_quota', false);
        
        if ($enableEqualQuota) {
            return new EqualQuotaStrategy(new EqualQuotaService());
        }
        
        return new GroupQuotaStrategy();
    }
    
    public function getAvailableQuota($crmUnit, $date)
    {
        return $this->strategy->getAvailableQuota($crmUnit, $date);
    }
    
    public function validateCampaignRequest($crmUnit, $date, $emailCount)
    {
        return $this->strategy->validateCampaignRequest($crmUnit, $date, $emailCount);
    }
    
    public function getOverviewData($crmUnit, $date)
    {
        return $this->strategy->getOverviewData($crmUnit, $date);
    }
    
    public function canBookCampaign($crmUnit, $date)
    {
        return $this->strategy->canBookCampaign($crmUnit, $date);
    }
    
    public function isEqualQuotaEnabled()
    {
        return config('app.enable_equal_quota', false);
    }
}