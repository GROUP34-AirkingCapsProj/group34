<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class IncidentReport extends Model
{
    use Auditable, ScopesToUserLocation;

    public const REASON_MISSING       = 'missing';
    public const REASON_DEFECTIVE     = 'defective';
    public const REASON_RETURN        = 'return';
    public const REASON_WRONG_TYPE    = 'wrong_type';
    public const REASON_ZONE_MISMATCH = 'zone_mismatch';
    public const REASON_OTHER         = 'other';

    /** Missing: why the reporter thinks it is gone. */
    public const SUSPECTED_CAUSES = ['theft', 'misplaced', 'unknown'];

    /** Defective: where the fault came from. */
    public const ROOT_CAUSES = ['supplier', 'internal', 'delivery', 'other'];

    /**
     * The second meaning of "return" the system never had: stock coming back
     * FROM a customer, versus stock going back TO a supplier.
     */
    public const RETURN_DIRECTIONS = ['from_customer', 'to_supplier'];

    /**
     * Reasons whose resolution moves stock, and which therefore cannot be
     * filed without a product and a quantity.
     *
     * zone_mismatch is deliberately still here: it needs a product to name
     * WHICH item is in the wrong place, even though resolving it moves
     * nothing. wrong_type re-types a batch, which is a movement between
     * products.
     */
    public const STOCK_AFFECTING_REASONS = [
        self::REASON_MISSING,
        self::REASON_DEFECTIVE,
        self::REASON_RETURN,
        self::REASON_WRONG_TYPE,
        self::REASON_ZONE_MISMATCH,
    ];

    public const PHOTO_SOURCE_CAPTURED = 'captured';
    public const PHOTO_SOURCE_UPLOADED = 'uploaded';

    /**
     * How the evidence photo reached us. This is a CLIENT-ASSERTED claim — both
     * paths arrive as identical multipart uploads, so the server cannot verify
     * it. Reviewer-facing wording must say "taken in-app", never "verified".
     */
    public const PHOTO_SOURCES = [
        self::PHOTO_SOURCE_CAPTURED,
        self::PHOTO_SOURCE_UPLOADED,
    ];

    /**
     * Every reason. Derived rather than hand-written so a new reason added to
     * the list above cannot be forgotten here — the two lists differ by exactly
     * one entry, and keeping that literal was an invitation to desync.
     */
    public const REASONS = [...self::STOCK_AFFECTING_REASONS, self::REASON_OTHER];

    protected $table      = 'incident_reports';
    protected $primaryKey = 'incident_id';

    protected $fillable = [
        'incident_number', 'source_type', 'source_id', 'related_issuance_id', 'location_id',
        'product_id', 'batch_id', 'serial_id', 'expected_zone', 'actual_zone',
        'reason', 'quantity', 'narrative', 'occurred_at', 'photo_path', 'photo_source', 'reported_by',
        'status_id', 'reviewed_by', 'reviewed_at',
        'adjustment_id', 'correction_id', 'resolution_note',
        'last_known_location', 'suspected_cause', 'root_cause',
        'return_direction', 'return_reason', 'related_transfer_id',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'reviewed_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    /** @var array<string,int> */
    private static array $statusCache = [];

    public static function statusId(string $name): int
    {
        if (isset(self::$statusCache[$name])) {
            return self::$statusCache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'incident')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown incident status: {$name}");
        }

        return self::$statusCache[$name] = (int) $id;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status_id', self::statusId('Open'));
    }

    /**
     * Filed but not finished — still Open, or approved and awaiting an
     * inspection that has not happened yet.
     *
     * This, not scopeOpen, is what FIFO exclusion must use. `Open` alone stopped
     * matching the moment Awaiting Inspection existed, which quietly returned
     * stock under investigation to the picking pool.
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereIn('status_id', [
            self::statusId('Open'),
            self::statusId('Awaiting Inspection'),
        ]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class, 'adjustment_id', 'adjustment_id');
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(ScanCorrection::class, 'correction_id', 'correction_id');
    }

    /** The sale a `return` came from. Null for every other reason. */
    public function relatedIssuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'related_issuance_id', 'issuance_id');
    }
}
