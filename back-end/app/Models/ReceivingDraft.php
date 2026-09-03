<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ReceivingDraft
 * Table: receiving_drafts
 *
 * One delivery being staged. Holds no stock and appears nowhere in inventory
 * until ReceivingDraftSubmitService replays its items through
 * ReceivingPostingService::post().
 */
class ReceivingDraft extends Model
{
    use Auditable;

    protected $table = 'receiving_drafts';
    protected $primaryKey = 'draft_id';

    protected $fillable = [
        'po_id',
        'location_id',
        'opened_by',
        'receiving_id',
        'status',
        'zone_label',
        'submitted_at',
        'discarded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'discarded_at' => 'datetime',
    ];

    /**
     * The draft a clerk should resume for this PO at this warehouse, or null.
     *
     * Scoped to `open` deliberately. A submitted draft describes a delivery
     * that already became a receipt; resuming it would stage goods against a
     * closed event and post them a second time.
     */
    public static function openFor(int $poId, int $locationId): ?self
    {
        return static::where('po_id', $poId)
            ->where('location_id', $locationId)
            ->where('status', 'open')
            ->with('items.product')
            ->first();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceivingDraftItem::class, 'draft_id', 'draft_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by', 'user_id');
    }

    /** The receipt this draft produced, once submitted. Null while open. */
    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }

    /** Total pieces waiting to be posted. */
    public function stagedPieces(): int
    {
        return (int) $this->items()->sum('quantity_amount');
    }

    /** Pieces staged against one PO line, for the dual progress display. */
    public function stagedPiecesForLine(int $poDetailId): int
    {
        return (int) $this->items()->where('po_detail_id', $poDetailId)->sum('quantity_amount');
    }

    /** Packages staged against one PO line. NULL rows contribute nothing. */
    public function stagedPackagesForLine(int $poDetailId): int
    {
        return (int) $this->items()
            ->where('po_detail_id', $poDetailId)
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(packages_received, 0)'));
    }
}
