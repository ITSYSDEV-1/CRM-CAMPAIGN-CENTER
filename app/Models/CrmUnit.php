<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_code',
        'name',
        'pepipost_account_id',
        'daily_quota',
        'monthly_quota',
        'mandatory_daily_quota',
        'max_sync_per_day',
        'is_active',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean'
    ];

    public function pepipostAccount(): BelongsTo
    {
        return $this->belongsTo(PepipostAccount::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function quotaUsage(): HasMany
    {
        return $this->hasMany(QuotaUsage::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    // Method untuk cek batas sync harian
    public function canSyncToday()
    {
        $today = now()->format('Y-m-d');
        $syncCount = $this->syncLogs()
            ->where('sync_date', $today)
            ->sum('sync_count');
            
        return $syncCount < $this->max_sync_per_day;
    }

    // Method untuk mendapatkan kuota tersedia unit (HANYA UNTUK REPORTING)
    public function getAvailableDailyQuota($date = null)
    {
        // Unit quota tidak lagi membatasi request
        // Unit bisa request sebanyak apapun selama group quota mencukupi
        // Return nilai besar untuk menunjukkan tidak ada batasan unit
        return 999999;
    }
}