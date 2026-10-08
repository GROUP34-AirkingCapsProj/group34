<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of one stock or money movement, frozen at the moment it was posted.
 *
 * Deliberately NOT Auditable: this table is itself the audit record, and rows are
 * never updated, so there would be nothing for the trait to log.
 */
class InventoryLedgerEntry extends Model
{
    protected $table = 'inventory_ledger';
    protected $primaryKey = 'ledger_id';

    public const DIRECTIONS = ['in', 'out', 'neutral'];
    public const COST_BASES = ['actual', 'product_default', 'derived_weighted_avg', 'unavailable'];

    protected $fillable = [
        'occurred_at', 'posted_at', 'direction', 'movement_type', 'lens',
        'product_id', 'batch_id', 'serial_number', 'quantity', 'unit_cost',
        'extended_value', 'cost_basis', 'from_location_id', 'to_location_id',
        'source_type', 'source_id', 'source_line_id', 'correlation_id',
        'actor_user_id', 'reverses_ledger_id', 'origin', 'posting_key', 'notes',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'posted_at'   => 'datetime',
        'quantity'    => 'integer',
        'product_id'  => 'integer',
        'batch_id'    => 'integer',
    ];

    public function isReversal(): bool
    {
        return $this->reverses_ledger_id !== null;
    }

    /**
     * True when the movement was recorded on a later day than it happened.
     *
     * Deliberately day-granular rather than minute-granular. Three of the four
     * source tables store their event date in a `date` column, so occurred_at
     * lands at midnight while posted_at is the wall clock — a minute-based
     * comparison flagged every routine same-day posting, which would have made a
     * genuinely backdated entry indistinguishable from normal operations.
     *
     * Carbon 3's diffInDays is signed, so a posted_at EARLIER than occurred_at
     * yields a negative difference and is intentionally NOT reported here: a
     * future-dated movement is a different anomaly from a backdated one.
     */
    public function isBackdated(int $toleranceDays = 1): bool
    {
        if (! $this->occurred_at || ! $this->posted_at) {
            return false;
        }

        return $this->occurred_at->startOfDay()->diffInDays($this->posted_at->startOfDay()) >= $toleranceDays;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id', 'location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id', 'location_id');
    }
}
