<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class ItemSerial extends Model
{
    use Auditable;
    protected $table = 'item_serial';

    /** Inventory status_id for "In Stock" (status_lookup category 'inventory'). */
    public const IN_STOCK_STATUS_ID = 26;
    protected $primaryKey = 'serial_id';

    protected $fillable = [
        'product_id',
        'location_id',
        'receiving_id',
        'batch_id',
        'scanned_by',
        'serial_number',
        'supplier_barcode',
        'serial_type',
        'transfer_id',
        'status_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function purchaseReturnDetails(): HasMany
    {
        return $this->hasMany(PurchaseReturnDetails::class, 'serial_id', 'serial_id');
    }

    /**
     * Serials that may be issued/sold at a location: physically here,
     * In Stock (inventory status 26), and not shipped out on a transfer
     * (serial_type stays 'received' until dispatched; receive() restores it).
     * Case/space-insensitive on the serial number to match scanner input.
     *
     * Matches EITHER identifier a unit can carry: the generated INT-... tag in
     * serial_number, or the supplier's own barcode in supplier_barcode. A
     * consumable's supplier barcode moved out of serial_number when tagging
     * landed, so matching serial_number alone silently stopped resolving those
     * scans to a unit — and with it, to the batch FIFO needs.
     *
     * The OR lives in its own closure: without the nesting it would bind
     * loosely and defeat the location / serial_type / status filtering above.
     * An empty scan string is never matched against supplier_barcode, so a
     * NULL or blank supplier_barcode cannot be resolved by an empty scan.
     */
    public function scopeIssuableAt($query, int $locationId, string $serialNumber)
    {
        $needle = strtolower(trim($serialNumber));

        return $query
            ->where('location_id', $locationId)
            ->where('serial_type', 'received')
            ->where('status_id', self::IN_STOCK_STATUS_ID)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(TRIM(serial_number)) = ?', [$needle]);

                if ($needle !== '') {
                    $q->orWhereRaw("LOWER(TRIM(COALESCE(supplier_barcode, ''))) = ?", [$needle]);
                }
            });
    }
}
