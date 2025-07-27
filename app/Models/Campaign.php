<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'crm_unit_id',
        'pepipost_account_id',
        'scheduled_date',
        'email_count',
        'campaign_type',
        'status',
        'subject',
        'description',
        'metadata',
        'requested_at',
        'approved_at',
        'sent_at',
        'rejection_reason'
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime'
    ];

    public function crmUnit(): BelongsTo
    {
        return $this->belongsTo(CrmUnit::class);
    }

    public function pepipostAccount(): BelongsTo
    {
        return $this->belongsTo(PepipostAccount::class);
    }

    // Scope untuk filter berdasarkan status
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('scheduled_date', $date);
    }
    
    // Scope untuk campaign yang aktif (tidak cancelled)
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'approved', 'sent']);
    }
    
    // Scope untuk campaign yang masih bisa dicancel
    public function scopeCancellable($query)
    {
        return $query->whereIn('status', ['pending', 'approved']);
    }
}