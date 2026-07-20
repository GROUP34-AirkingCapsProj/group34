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
    ];

    protected $casts = [
        'quantity_issued'       => 'integer',
        'quantity_returned'     => 'integer',
        'quantity_in_pieces'    => 'integer',
        'unit_price'            => 'decimal:2',
        'unit_price_per_piece'  => 'decimal:4',
        'subtotal'              => 'decimal:2',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id')
                    ->with(['applianceDetail.brand', 'consumableDetail.unit']);
    }

    public function warrantyRecord(): HasOne
    {
        return $this->hasOne(WarrantyRecord::class, 'issuance_detail_id', 'issuance_detail_id');
    }
}
