<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

/**
 * ReceivingDetail (ERD: RECEIVING_DETAIL)
 * Table: receiving_details
 * Line items for each receiving transaction.
 */
class ReceivingDetail extends Model
{
    use Auditable;
    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\IncomingStockService::markDirty());
    }
    protected $table = 'receiving_details';
    protected $primaryKey = 'receiving_detail_id';

    protected $fillable = [
        'receiving_id',
        'po_detail_id',
        'product_id',
        'aircon_model_id',
        'prod_price',
        'quantity_amount',
        'condition',
        'scanned_barcode',
        'entry_mode',
        'packages_received',
        'pieces_expected',
        'contents_verified_at',
    ];

    protected $casts = [
        'prod_price'           => 'decimal:2',
        'quantity_amount'      => 'integer',
        'contents_verified_at' => 'datetime',
    ];

    /**
     * `contents_verified_at` has exactly one meaning: NULL is a package whose
     * contents nobody has checked yet. Rows predating the column were
     * backfilled by its migration rather than left NULL, so no reader needs a
     * cutoff date to tell legacy data from a genuine unverified receipt.
     */

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }

    public function purchaseOrderDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDetail::class, 'po_detail_id', 'po_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * The aircon model this line belongs to, or null for a standalone line.
     * Carried here rather than read through po_detail_id because a scan-driven
     * receipt has no purchase-order line behind it and still needs to group.
     */
    public function airconModel(): BelongsTo
    {
        return $this->belongsTo(AirconModel::class, 'aircon_model_id', 'aircon_model_id');
    }
}
