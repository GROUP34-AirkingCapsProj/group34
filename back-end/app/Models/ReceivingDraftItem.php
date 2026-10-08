<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReceivingDraftItem
 * Table: receiving_draft_items
 *
 * One staged scan or count. Its columns mirror the parameters of
 * ReceivingPostingService::post() one-for-one so that submitting is a spread,
 * not a translation.
 */
class ReceivingDraftItem extends Model
{
    use Auditable;

    protected $table = 'receiving_draft_items';
    protected $primaryKey = 'draft_item_id';

    protected $fillable = [
        'draft_id',
        'product_id',
        'po_detail_id',
        'entry_mode',
        'scanned_barcode',
        'quantity_amount',
        'packages_received',
        'pieces_expected',
        'contents_verified',
        'units_entered',
    ];

    protected $casts = [
        'quantity_amount'   => 'integer',
        'packages_received' => 'integer',
        'pieces_expected'   => 'integer',
        'units_entered'     => 'integer',
        'contents_verified' => 'boolean',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ReceivingDraft::class, 'draft_id', 'draft_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function purchaseOrderDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDetail::class, 'po_detail_id', 'po_detail_id');
    }
}
