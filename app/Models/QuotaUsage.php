<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotaUsage extends Model
{
    use HasFactory;


    protected $table = 'quota_usage';

    protected $fillable = [
        'pepipost_account_id',
        'crm_unit_id',
        'usage_date',
        'daily_used',
        'monthly_used',
        'mandatory_used',
        'breakdown'
    ];

    protected $casts = [
        'usage_date' => 'date',
        'breakdown' => 'array'
    ];

    public function pepipostAccount(): BelongsTo
    {
        return $this->belongsTo(PepipostAccount::class);
    }

    public function crmUnit(): BelongsTo
    {
        return $this->belongsTo(CrmUnit::class);
    }
}