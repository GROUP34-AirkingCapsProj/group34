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
        'spec_note', 'sort_order',
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

    public function quantityInPieces(): int
    {
        return (int) round((float) $this->quantity);
    }
}
