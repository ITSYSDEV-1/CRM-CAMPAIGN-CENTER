<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'crm_unit_id',
        'sync_date',
        'sync_count',
        'sync_type',
        'sync_data'
    ];

    protected $casts = [
        'sync_date' => 'date',
        'sync_data' => 'array'
    ];

    public function crmUnit(): BelongsTo
    {
        return $this->belongsTo(CrmUnit::class);
    }
}