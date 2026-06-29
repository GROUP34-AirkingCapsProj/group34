<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use App\Models\Concerns\ScopesToUserLocation;

class Adjustment extends Model
{
    use SoftDeletes, Auditable, ScopesToUserLocation;

    protected $table      = 'adjustments';
    protected $primaryKey = 'adjustment_id';

    protected $fillable = [
        'location_id',
        'po_id',
        'receiving_id',
        'created_by',
        'adjusted_by',
        'approved_by',
        'adjustment_number',
        'adjustment_date',
        'adjustment_type',
        'total_variance_positive',
        'total_variance_negative',
        'notes',
        'status_id',
    ];

    protected $casts = [
        'adjustment_date'         => 'date',
        'total_variance_positive' => 'integer',
        'total_variance_negative' => 'integer',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(AdjustmentDetail::class, 'adjustment_id', 'adjustment_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ScanCorrection::class, 'adjustment_id', 'adjustment_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }
}
