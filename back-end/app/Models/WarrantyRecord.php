<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class WarrantyRecord extends Model
{
    use Auditable;

    protected $table      = 'warranty_records';
    protected $primaryKey = 'warranty_id';

    protected $fillable = [
        'issuance_id', 'issuance_detail_id', 'product_id',
        'serial_number', 'customer_id',
        'sale_date', 'warranty_start_date', 'warranty_end_date',
        'warranty_period_months', 'warranty_duration_label',
        'status_id', 'claim_date', 'claimed_by',
        'claim_notes', 'notes',
    ];

    protected $casts = [
        'sale_date'              => 'date',
        'warranty_start_date'    => 'date',
        'warranty_end_date'      => 'date',
        'claim_date'             => 'date',
        'warranty_period_months' => 'integer',
    ];

    protected $appends = ['days_remaining', 'is_expired'];

    public function issuance()
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id')
                    ->with(['applianceDetail.brand']);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function claimedByUser()
    {
        return $this->belongsTo(User::class, 'claimed_by', 'user_id');
    }

    public function claims()
    {
        return $this->hasMany(WarrantyClaim::class, 'warranty_id', 'warranty_id');
    }

    public function issuanceDetail()
    {
        return $this->belongsTo(IssuanceDetail::class, 'issuance_detail_id', 'issuance_detail_id');
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->warranty_end_date !== null
            && now()->isAfter($this->warranty_end_date);
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->warranty_end_date) return null;
        return (int) now()->diffInDays($this->warranty_end_date, false);
    }
}
