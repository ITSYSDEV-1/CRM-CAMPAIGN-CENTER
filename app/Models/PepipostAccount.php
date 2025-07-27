<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $this->hasMany(CrmUnit::class);
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
        
        $totalUsed = $this->quotaUsage()
            ->where('usage_date', $date)
            ->sum('daily_used');
            
        // Hitung kuota yang sudah direservasi oleh kampanye aktif (tidak termasuk cancelled)
        $reservedQuota = $this->campaigns()
            ->where('scheduled_date', $date)
            ->active() // Gunakan scope active yang sudah ada
            ->sum('email_count');
            
        $mandatoryQuota = $this->crmUnits()
            ->where('is_active', true)
            ->sum('mandatory_daily_quota');
            
        return $this->daily_quota - $totalUsed - $reservedQuota - $mandatoryQuota;
    }
    
    public function getReservedDailyQuota($date = null)
    {
        $date = $date ?? now()->format('Y-m-d');
        
        return $this->campaigns()
            ->where('scheduled_date', $date)
            ->active() // Gunakan scope active yang sudah ada
            ->sum('email_count');
    }
    
    public function getAvailableMonthlyQuota($month = null, $year = null)
    {
        $month = $month ?? now()->month;
        $year = $year ?? now()->year;
        
        $totalUsed = $this->quotaUsage()
            ->whereMonth('usage_date', $month)
            ->whereYear('usage_date', $year)
            ->sum('monthly_used');
            
        // Hitung kuota yang sudah direservasi oleh kampanye aktif
        $reservedQuota = $this->campaigns()
            ->whereMonth('scheduled_date', $month)
            ->whereYear('scheduled_date', $year)
            ->active() // Gunakan scope active yang sudah ada
            ->sum('email_count');
            
        return $this->monthly_quota - $totalUsed - $reservedQuota;
    }
}
