<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSetLine extends Model
{
    protected $table      = 'product_set_lines';
    protected $primaryKey = 'set_line_id';

    protected $fillable = [
        'set_id', 'product_id', 'quantity',
        'issuable_unit_id', 'spec_note', 'sort_order',
    ];

    protected $casts = [
        'quantity'   => 'float',
        'sort_order' => 'integer',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(ProductSet::class, 'set_id', 'set_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function issuableUnit(): BelongsTo
    {
        return $this->belongsTo(ProductIssuableUnit::class, 'issuable_unit_id', 'issuable_unit_id');
    }

    /**
     * Quantity expressed in base pieces, applying the issuable unit's conversion_factor.
     * A line with no issuable unit is already in pieces.
     */
    public function quantityInPieces(): int
    {
        $factor = (float) ($this->issuableUnit?->conversion_factor ?? 1);
        if ($factor <= 0) {
            $factor = 1;
        }

        return (int) ceil((float) $this->quantity * $factor);
    }
}
