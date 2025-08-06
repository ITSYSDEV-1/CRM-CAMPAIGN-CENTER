<?php

namespace App\Services\QuotaStrategy;

interface QuotaStrategyInterface
{
    public function getAvailableQuota($crmUnit, $date);
    public function validateCampaignRequest($crmUnit, $date, $emailCount);
    public function getOverviewData($crmUnit, $date);
    public function canBookCampaign($crmUnit, $date);
}