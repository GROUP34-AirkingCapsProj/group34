<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class PurchaseOrder extends Model
{
    use Auditable;
    protected $table = 'purchase_orders';
    protected $primaryKey = 'po_id';

    protected $fillable = [
        'pr_id',
        'supplier_id',
        'location_id',
        'po_number',
        'order_date',
        'expected_delivery_date',
        'total_amount',
        'grand_total',
        'status_id',
        'created_by',
        'approved_by',
        'received_by',
    ];

    protected $casts = [
        'order_date'             => 'date',
        'expected_delivery_date' => 'date',
        'total_amount'           => 'decimal:2',
        'grand_total'            => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id', 'pr_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseOrderDetail::class, 'po_id', 'po_id');
    }

    public function receivings(): HasMany
    {
        return $this->hasMany(Receiving::class, 'po_id', 'po_id');
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'po_id', 'po_id');
    }
}
