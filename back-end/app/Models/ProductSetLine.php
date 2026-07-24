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

    /**
     * Includes archived products on purpose. A BOQ line still refers to the
     * product it was authored with even after that product is soft-deleted;
     * without withTrashed() the line would render nameless and price as zero,
     * silently understating the set total instead of showing the real figure.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id')->withTrashed();
    }

    public function issuableUnit(): BelongsTo
    {
        return $this->belongsTo(ProductIssuableUnit::class, 'issuable_unit_id', 'issuable_unit_id');
    }

    /**
     * Quantity expressed in base pieces, applying the issuable unit's conversion_factor.
     * A line with no issuable unit is already in pieces.
     *
     * The round() before ceil() is load-bearing, not decoration. quantity is a
     * decimal(10,3), so ordinary values land on binary fractions that overshoot:
     * 1.1 * 100 evaluates to 110.00000000000001, and a bare ceil() would issue
     * 111 pieces instead of 110. Six decimals is far finer than any real
     * conversion factor while sitting well clear of that float noise. A genuine
     * fraction still rounds up as intended — 1.5 pieces is still 2.
     */
    public function quantityInPieces(): int
    {
        $factor = (float) ($this->issuableUnit?->conversion_factor ?? 1);
        if ($factor <= 0) {
            $factor = 1;
        }

        return (int) ceil(round((float) $this->quantity * $factor, 6));
    }
}
