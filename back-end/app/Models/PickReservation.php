<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickReservation extends Model
{
    protected $table      = 'pick_reservations';
    protected $primaryKey = 'reservation_id';

    protected $fillable = [
        'source_type', 'source_id', 'product_id', 'location_id',
        'batch_id', 'serial_id', 'quantity', 'expires_at', 'released_at',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'expires_at'  => 'datetime',
        'released_at' => 'datetime',
    ];

    /** Reservations still holding stock: not released and not expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->where('expires_at', '>', now());
    }

    public function scopeForSource(Builder $query, string $type, int $id): Builder
    {
        return $query->where('source_type', $type)->where('source_id', $id);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
