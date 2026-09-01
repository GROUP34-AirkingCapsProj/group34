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
    ];

    protected $casts = [
        'prod_price'      => 'decimal:2',
        'quantity_amount' => 'integer',
    ];

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
