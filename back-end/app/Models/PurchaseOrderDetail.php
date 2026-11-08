<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PurchaseOrderDetail (ERD: purchase_order_detail)
 * Table: purchase_order_details
 * Line items for each purchase order.
 */
class PurchaseOrderDetail extends Model
{
    use Auditable;

    protected $table = 'purchase_order_details';

    protected $primaryKey = 'po_detail_id';

    protected $fillable = [
        'po_id',
        'location_id',
        'product_id',
        'aircon_model_id',
        'quantity_ordered',
        'unit_price',
        'subtotal',
        'order_unit',
        'pieces_per_order_unit',
        'quantity_snapshot_source',
        'short_closed_quantity',
        'replacement_for_po_detail_id',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'pieces_per_order_unit' => 'integer',
        'short_closed_quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\IncomingStockService::markDirty());
        static::deleted(fn () => \App\Services\IncomingStockService::markDirty());
        static::creating(function (self $line) {
            $product = Product::withTrashed()->with('installationMaterialDetail')->find($line->product_id);
            $material = $product?->product_type === 'installation_material';
            $line->order_unit = $material ? 'package' : 'piece';
            $line->pieces_per_order_unit = $material ? max(1, (int) $product->getPiecesPerPackage()) : 1;
            $line->quantity_snapshot_source = 'creation';
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * The aircon model this line belongs to, or null for a standalone line.
     * Lines sharing a model on the same PO render as one grouped header.
     */
    public function airconModel(): BelongsTo
    {
        return $this->belongsTo(AirconModel::class, 'aircon_model_id', 'aircon_model_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function receivingDetails(): HasMany
    {
        return $this->hasMany(ReceivingDetail::class, 'po_detail_id', 'po_detail_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }
}
