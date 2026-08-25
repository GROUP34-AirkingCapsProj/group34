<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AirconModelUnit extends Model
{
    protected $table      = 'aircon_model_units';
    protected $primaryKey = 'aircon_model_unit_id';

    protected $fillable = [
        'aircon_model_id', 'product_id', 'quantity', 'sort_order',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'sort_order' => 'integer',
    ];

    public function airconModel(): BelongsTo
    {
        return $this->belongsTo(AirconModel::class, 'aircon_model_id', 'aircon_model_id');
    }

    /**
     * Includes archived products on purpose, for the same reason
     * ProductSetLine::product() does: a model line still refers to the product
     * it was authored with even after that product is soft-deleted. Without
     * withTrashed() the line would render nameless and price as zero, silently
     * understating the model total instead of showing the real figure.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id')->withTrashed();
    }

    /**
     * Which side of the pair this line is.
     *
     * Read from the member product, never stored on this row — the product's
     * appliance_details.unit_role is the single source of truth. See the
     * create-tables migration's down() docblock for why.
     */
    public function unitRole(): ?string
    {
        return $this->product?->applianceDetail?->unit_role;
    }
}
