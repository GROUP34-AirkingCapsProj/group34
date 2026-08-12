<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        // The client's document block, filled by the reporter.
        'dealer_name', 'dealer_phone', 'dealer_mobile', 'dealer_email', 'branch_address',
        'customer_name', 'customer_contact', 'site_address', 'purchased_at', 'received_at_dor',
        // The inspection record, filled by the inspector. The signature columns
        // hold the names printed on the hardcopy that is signed by hand and
        // carried to the admin — a record that signing happened, not a
        // digital signature.
        'inspected_by', 'inspected_at', 'inspection_findings', 'checked_by_asc',
        'signed_by_representative', 'signed_by_branch_manager', 'signed_at',
        'rejection_note',
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'reviewed_at'     => 'datetime',
        'occurred_at'     => 'datetime',
        'inspected_at'    => 'datetime',
        'checked_by_asc'  => 'boolean',
        'purchased_at'    => 'date',
        'received_at_dor' => 'date',
        'signed_at'       => 'date',
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

    /** The physical things this report is about — one row per unit. */
    public function items(): HasMany
    {
        return $this->hasMany(IncidentItem::class, 'incident_id', 'incident_id');
    }

    /** The inspector's attachments against the client's checklist. */
    public function evidence(): HasMany
    {
        return $this->hasMany(IncidentEvidence::class, 'incident_id', 'incident_id');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by', 'user_id');
    }

    /**
     * The report's own status row, so it can be serialized by NAME.
     *
     * Every client-facing payload sends `status_name`, never a bare id: the
     * incident statuses are install-specific — Open/Resolved/Dismissed/Awaiting
     * Inspection are 46/47/48/53 in the dev database and 765/766/767/1862 in
     * the test one — so a frontend that switches on the number works here and
     * is silently wrong on any other install.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
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
