<?php

namespace App\Models;

use App\Services\PoLineBalanceService;
use App\Support\PoLifecycle;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'purchase_orders';

    protected $primaryKey = 'po_id';

    protected $fillable = [
        'pr_id',
        'supplier_id',
        'location_id',
        'po_number',
        'order_date',
        'expected_delivery_date',
        'payment_terms',
        'total_amount',
        'grand_total',
        'notes',
        'references_note',
        'status_id',
        'created_by',
        'approved_by',
        'verified_by',
        'verification_status',
        'verified_at',
        'verification_note',
        'ship_to_name',
        'ship_to_address',
        'received_by',
        'lifecycle_status',
        'original_expected_delivery_date',
        'replacement_for_po_id',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'total_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'verified_at' => 'datetime',
        'original_expected_delivery_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\IncomingStockService::markDirty());
        static::deleted(fn () => \App\Services\IncomingStockService::markDirty());
        static::restored(fn () => \App\Services\IncomingStockService::markDirty());
        static::deleting(function (self $po) {
            if (! $po->isForceDeleting() && in_array((int) $po->status_id, [8, 10, 11, 12], true)
                && array_sum(array_column(app(PoLineBalanceService::class)->forPo($po), 'remaining')) > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['po' => 'Close remaining quantities before archiving this PO.']);
            }
            if ($po->isForceDeleting() && ($po->receivings()->exists() || $po->purchaseReturns()->exists()
                || $po->details()->where('short_closed_quantity', '>', 0)->exists()
                || $po->details()->whereHas('receivingDetails')->exists()
                || self::withTrashed()->where('replacement_for_po_id', $po->po_id)->exists())) {
                throw \Illuminate\Validation\ValidationException::withMessages(['po' => 'PO receiving and lifecycle evidence must be retained permanently.']);
            }
        });
        static::creating(function (self $po) {
            $po->original_expected_delivery_date = $po->expected_delivery_date;
            $po->lifecycle_status = in_array((int) $po->status_id, [8, 10], true)
                ? PoLifecycle::OPEN : PoLifecycle::DRAFT;
        });
        static::updating(function (self $po) {
            if ($po->original_expected_delivery_date === null && $po->expected_delivery_date !== null) {
                $po->original_expected_delivery_date = $po->expected_delivery_date;
            }
            if ($po->isDirty('status_id')) {
                $balances = app(PoLineBalanceService::class)->forPo($po);
                $po->lifecycle_status = PoLifecycle::compute(array_values($balances),
                    in_array((int) $po->status_id, [8, 10, 11, 12], true),
                    $po->lifecycle_status === PoLifecycle::CANCELLED);
            }
        });
    }

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

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
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
