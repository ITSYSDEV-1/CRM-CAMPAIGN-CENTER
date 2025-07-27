<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use App\Services\BillingCycleService;
class PepipostAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'api_key',
        'daily_quota',
        'monthly_quota',
        'is_active',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean'
    ];

    public function crmUnits(): HasMany
    {
        return $this->hasMany(\App\Models\CrmUnit::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function quotaUsage(): HasMany
    {
        return $this->hasMany(QuotaUsage::class);
    }

    // Method untuk menghitung kuota tersedia
    public function getAvailableDailyQuota($date = null)
    {
        $date = $date ?? now()->format('Y-m-d');
        
        // PERBAIKAN: Jangan gunakan quota usage dari unit
        // $totalUsed = $this->quotaUsage()
        //     ->where('usage_date', $date)
        //     ->sum('daily_used');
            
        // Hitung kuota yang sudah direservasi oleh kampanye aktif (tidak termasuk cancelled)
        $reservedQuota = $this->campaigns()
            ->where('scheduled_date', $date)
            ->active() // Gunakan scope active yang sudah ada
            ->sum('email_count');
            
        $mandatoryQuota = $this->crmUnits()
            ->where('is_active', true)
            ->sum('mandatory_daily_quota');
            
        // PERBAIKAN: Hanya kurangi reservasi dan mandatory, bukan quota usage
        return $this->daily_quota - $reservedQuota - $mandatoryQuota;
    }

    public function getAvailableMonthlyQuota($date = null)
    {
        $billingPeriod = BillingCycleService::getBillingPeriod($date);
        
        // Hitung kuota yang sudah direservasi oleh kampanye aktif
        $reservedQuota = $this->campaigns()
            ->whereBetween('scheduled_date', [$billingPeriod['start_date'], $billingPeriod['end_date']])
            ->active()
            ->sum('email_count');
        
        // Hitung mandatory quota untuk periode billing
        $startDate = Carbon::parse($billingPeriod['start_date']);
        $endDate = Carbon::parse($billingPeriod['end_date']);
        $daysInPeriod = $startDate->diffInDays($endDate) + 1;
        
        $mandatoryMonthlyQuota = $this->crmUnits()
            ->where('is_active', true)
            ->sum('mandatory_daily_quota') * $daysInPeriod;
            
        return $this->monthly_quota - $reservedQuota - $mandatoryMonthlyQuota;
    }
}
