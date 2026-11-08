<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;
use App\Models\Concerns\ScopesToUserLocation;

/**
 * Receiving (ERD: RECEIVING)
 * Table: receivings
 * Fields: receiving_id, po_id FK, supplier_id FK, location_id FK,
 *         receiving_number, receiving_date, received_by FK,
 *         total_quantity_received, total_quantity_damaged
 */
class Receiving extends Model
{
    use Auditable, ScopesToUserLocation;
    protected $table = 'receivings';
    protected $primaryKey = 'receiving_id';

    protected static function booted(): void
    {
        static::deleting(function () {
            throw \Illuminate\Validation\ValidationException::withMessages(['receiving' => 'Posted receipts must be retained permanently.']);
        });
    }

    protected $fillable = [
        'po_id',
        'supplier_id',
        'location_id',
        'receiving_number',
        'receiving_date',
        'received_by',
        'total_quantity_received',
        'total_quantity_damaged',
    ];

    protected $casts = [
        'receiving_date'          => 'date',
        'total_quantity_received' => 'integer',
        'total_quantity_damaged'  => 'integer',
    ];

    /**
     * Replace the temporary insert value with a readable, globally unique GRN.
     * The database primary key supplies uniqueness without hash-like text.
     */
    public function assignReadableNumber(): string
    {
        $date = $this->receiving_date
            ? $this->receiving_date->format('Ymd')
            : now()->format('Ymd');
        $number = sprintf('GRN-%s-%06d', $date, (int) $this->receiving_id);

        if ($this->receiving_number !== $number) {
            $this->updateQuietly(['receiving_number' => $number]);
        }

        return $number;
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ReceivingDetail::class, 'receiving_id', 'receiving_id');
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'receiving_id', 'receiving_id');
    }
}
