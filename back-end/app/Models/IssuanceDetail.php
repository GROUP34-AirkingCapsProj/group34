<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\Auditable;

class IssuanceDetail extends Model
{
    use Auditable;

    protected $table      = 'issuance_details';
    protected $primaryKey = 'issuance_detail_id';

    protected $fillable = [
        'issuance_id', 'product_id', 'serial_number', 'receiving_id',
        'unit_price', 'quantity_issued', 'quantity_unit',
        'quantity_in_pieces', 'unit_price_per_piece', 'subtotal',
        'quantity_returned', 'condition_issued', 'condition_returned',
        'issuance_set_id', 'batch_id', 'zone_label',
        'stock_before', 'quantity_deducted', 'stock_after',
        'cost_price_at_issue', 'quantity_requested', 'quantity_short',
    ];

    protected $casts = [
        'quantity_issued'       => 'integer',
        'quantity_returned'     => 'integer',
        'quantity_in_pieces'    => 'integer',
        'unit_price'            => 'decimal:2',
        'unit_price_per_piece'  => 'decimal:4',
        'subtotal'              => 'decimal:2',
        'quantity_requested'    => 'integer',
        'quantity_short'        => 'integer',
        'cost_price_at_issue'   => 'decimal:2',
        'stock_before'          => 'integer',
        'quantity_deducted'     => 'integer',
        'stock_after'           => 'integer',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    public function product(): BelongsTo
    {
        // withTrashed(): an issuance line is a historical record and must always
        // resolve its product, even after that product is archived. Without it,
        // an archived product mid-draft makes the relation null — the quote then
        // prices the line's cost at zero and renders no name. Same reasoning as
        // ProductSetLine::product().
        return $this->belongsTo(Product::class, 'product_id', 'product_id')
                    ->withTrashed()
                    ->with(['applianceDetail.brand', 'consumableDetail.unit']);
    }

    public function warrantyRecord(): HasOne
    {
        return $this->hasOne(WarrantyRecord::class, 'issuance_detail_id', 'issuance_detail_id');
    }

    public function issuanceSet(): BelongsTo
    {
        return $this->belongsTo(IssuanceSet::class, 'issuance_set_id', 'issuance_set_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }
}
