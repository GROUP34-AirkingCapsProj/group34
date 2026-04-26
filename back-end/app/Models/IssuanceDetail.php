<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IssuanceDetail (ERD: issuance_detail)
 * Table: issuance_details
 * Line items for each issuance transaction.
 */
class IssuanceDetail extends Model
{
    protected $table = 'issuance_details';
    protected $primaryKey = 'issuance_detail_id';

    protected $fillable = [
        'issuance_id',
        'product_id',
        'quantity_issued',
        'quantity_returned',
        'condition_issued',
        'condition_returned',
    ];

    protected $casts = [
        'quantity_issued'   => 'integer',
        'quantity_returned' => 'integer',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
