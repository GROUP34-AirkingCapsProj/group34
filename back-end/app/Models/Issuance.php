<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use App\Models\Concerns\ScopesToUserLocation;

class Issuance extends Model
{
    use SoftDeletes, Auditable, ScopesToUserLocation;

    protected $table      = 'issuances';
    protected $primaryKey = 'issuance_id';

    protected $fillable = [
        'location_id', 'customer_id', 'issuance_date',
        'issuance_type', 'dr_number', 'external_reference', 'discount_type',
        'discount_percent', 'subtotal', 'discount_amount',
        'total_amount', 'purpose', 'issued_to_user_id',
        'expected_return_date', 'actual_return_date',
        'issued_by', 'approved_by', 'status_id', 'notes',
    ];

    protected $casts = [
        'issuance_date'        => 'date',
        'expected_return_date' => 'date',
        'actual_return_date'   => 'date',
        'discount_percent'     => 'decimal:2',
        'subtotal'             => 'decimal:2',
        'discount_amount'      => 'decimal:2',
        'total_amount'         => 'decimal:2',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function issuedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to_user_id', 'user_id');
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(IssuanceDetail::class, 'issuance_id', 'issuance_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(IssuanceSet::class, 'issuance_id', 'issuance_id');
    }

    public function warrantyRecords(): HasMany
    {
        return $this->hasMany(WarrantyRecord::class, 'issuance_id', 'issuance_id');
    }

    public function deliveryReceipt(): HasOne
    {
        return $this->hasOne(DeliveryReceipt::class, 'issuance_id', 'issuance_id');
    }
}
