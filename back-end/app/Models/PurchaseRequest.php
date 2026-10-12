<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class PurchaseRequest extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'purchase_requests';
    protected $primaryKey = 'pr_id';

    protected $fillable = [
        'location_id',
        'supplier_id',
        'pr_number',
        'request_date',
        'needed_by_date',
        'purpose',
        'estimated_total',
        'status_id',
        'requested_by',
        'approved_by',
        'approval_date',
        'rejection_reason',
        'converted_to_po_id',
    ];

    protected $casts = [
        'request_date'    => 'date',
        'needed_by_date'  => 'date',
        'approval_date'   => 'date',
        'estimated_total' => 'decimal:2',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseRequestDetail::class, 'pr_id', 'pr_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function convertedPO(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'converted_to_po_id', 'po_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function isPending(): bool          { return $this->status_id === 13; }
    public function isApproved(): bool         { return $this->status_id === 14; }
    public function isRejected(): bool         { return $this->status_id === 15; }
    public function isPartiallyApproved(): bool{ return $this->status_id === 16; }
}
