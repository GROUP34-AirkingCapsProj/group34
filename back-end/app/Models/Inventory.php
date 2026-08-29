<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Models\Concerns\ScopesToUserLocation;

class Inventory extends Model
{
    use Auditable, ScopesToUserLocation;

    protected $table = 'inventory';

    protected $primaryKey = 'inventory_id';

    protected $fillable = [
        'location_id',
        'product_id',
        'quantity_on_hand',
        'packages_on_hand',
        'available_quantity',
        'reorder_level',
    ];

    protected $casts = [
        'quantity_on_hand'   => 'integer',
        'packages_on_hand'   => 'integer',
        'available_quantity' => 'integer',
        'reorder_level'      => 'integer',
    ];

    /**
     * The single writer for stock levels.
     *
     * Pieces are the source of truth; packages_on_hand is derived. available_quantity
     * moves by the DELTA rather than being assigned, because issuance, transfers and
     * warranty claims apply relative decrements clamped at zero — assigning parity
     * would restore phantom availability wherever the two columns had diverged.
     */
    public function setPieces(int $pieces, int $piecesPerPackage): void
    {
        $delta = $pieces - (int) $this->quantity_on_hand;

        $this->quantity_on_hand   = $pieces;
        $this->available_quantity = max(0, (int) $this->available_quantity + $delta);
        $this->packages_on_hand   = $piecesPerPackage > 0
            ? intdiv($pieces, $piecesPerPackage)
            : 0;
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }
}