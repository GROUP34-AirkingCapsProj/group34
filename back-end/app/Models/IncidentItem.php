<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One physical thing on an incident report.
 *
 * Never write status or outcome directly — IncidentItemService is the sole
 * mutator, because changing an item's status and moving the matching stock have
 * to happen together or `quantity_on_hand = pickable + quarantined` breaks
 * silently, and nobody notices until a physical count.
 */
class IncidentItem extends Model
{
    use Auditable;

    /**
     * NOTE: no ScopesToUserLocation. That trait filters a `location_id` COLUMN,
     * and an item has none — location lives on the parent report, because an
     * item cannot be somewhere its incident isn't. Anything needing location
     * scoping joins `incident_reports` and filters `ir.location_id`, the way
     * quarantinedBatchIds() below does.
     */

    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_AVAILABLE   = 'available';
    public const STATUS_SCRAPPED    = 'scrapped';
    public const STATUS_RETURNED    = 'returned';
    public const STATUS_MISSING     = 'missing';

    public const STATUSES = [
        self::STATUS_QUARANTINED, self::STATUS_AVAILABLE,
        self::STATUS_SCRAPPED, self::STATUS_RETURNED, self::STATUS_MISSING,
    ];

    public const OUTCOME_RESTOCK            = 'restock';
    public const OUTCOME_SCRAP              = 'scrap';
    public const OUTCOME_RETURN_TO_SUPPLIER = 'return_to_supplier';
    public const OUTCOME_WRITE_OFF          = 'write_off';

    public const OUTCOMES = [
        self::OUTCOME_RESTOCK, self::OUTCOME_SCRAP,
        self::OUTCOME_RETURN_TO_SUPPLIER, self::OUTCOME_WRITE_OFF,
    ];

    /** What each outcome leaves the item as, once the admin confirms. */
    public const OUTCOME_STATUS = [
        self::OUTCOME_RESTOCK            => self::STATUS_AVAILABLE,
        self::OUTCOME_SCRAP              => self::STATUS_SCRAPPED,
        self::OUTCOME_RETURN_TO_SUPPLIER => self::STATUS_RETURNED,
        self::OUTCOME_WRITE_OFF          => self::STATUS_MISSING,
    ];

    /**
     * The only outcome that returns quantity to pickable stock. Everything else
     * deducts it — the goods left the building or were destroyed.
     */
    public const OUTCOME_RESTOCKS = [self::OUTCOME_RESTOCK];

    public const ROLE_SINGLE  = 'single';
    public const ROLE_INDOOR  = 'indoor';
    public const ROLE_OUTDOOR = 'outdoor';
    public const ROLES = [self::ROLE_SINGLE, self::ROLE_INDOOR, self::ROLE_OUTDOOR];

    protected $table      = 'incident_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'incident_id', 'product_id', 'serial_id', 'batch_id', 'unit_role',
        'model_code', 'serial_number', 'quantity', 'status', 'outcome', 'outcome_note',
    ];

    protected $casts = ['quantity' => 'integer'];

    public function scopeQuarantined(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_QUARANTINED);
    }

    /**
     * Batch ids currently unpickable because an item on an unfinished report
     * names them.
     *
     * Driven from ITEM status, not incident status. Deriving it from the
     * incident's status broke once already: adding "Awaiting Inspection"
     * silently returned stock under investigation to the picking pool, because
     * the query filtered on "Open".
     */
    public static function quarantinedBatchIds(int $locationId): array
    {
        return DB::table('incident_items as ii')
            ->join('incident_reports as ir', 'ir.incident_id', '=', 'ii.incident_id')
            ->where('ir.location_id', $locationId)
            ->where('ii.status', self::STATUS_QUARANTINED)
            ->whereNotNull('ii.batch_id')
            ->distinct()
            ->pluck('ii.batch_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_id', 'incident_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * The catalogued unit, when the report named one.
     *
     * Distinct from this row's own `serial_number`, which is whatever is
     * physically printed on the sticker — those two disagreeing is often the
     * incident itself, so neither can stand in for the other silently.
     */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'serial_id', 'serial_id');
    }
}
