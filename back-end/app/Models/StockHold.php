<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A quantity of stock that exists but is not in active inventory.
 *
 * Never write to this table directly — StockHoldService is the sole mutator,
 * because creating a hold and deducting active stock have to happen together
 * or the invariant `physical = active + held` silently breaks.
 */
class StockHold extends Model
{
    use Auditable, ScopesToUserLocation;

    public const CONDITION_MISSING   = 'missing';
    public const CONDITION_DEFECTIVE = 'defective';
    public const CONDITION_RETURNED  = 'returned';

    public const CONDITIONS = [
        self::CONDITION_MISSING,
        self::CONDITION_DEFECTIVE,
        self::CONDITION_RETURNED,
    ];

    public const STATUS_HELD     = 'held';
    public const STATUS_RELEASED = 'released';

    public const DISPOSITION_RESTOCKED            = 'restocked';
    public const DISPOSITION_DEFECTIVE            = 'defective';
    public const DISPOSITION_RETURNED_TO_SUPPLIER = 'returned_to_supplier';
    public const DISPOSITION_SCRAPPED             = 'scrapped';

    /**
     * Which dispositions each condition allows.
     *
     * `missing` is absent entirely: it is terminal on approval. An item that
     * turns up later comes back through a new report, deliberately, rather
     * than by quietly reversing a loss record.
     *
     * `defective` cannot be disposed as `defective` (it already is) and cannot
     * be `restocked` — there must be no one-click path that puts a known-bad
     * unit back into picking.
     */
    public const ALLOWED_DISPOSITIONS = [
        self::CONDITION_RETURNED => [
            self::DISPOSITION_RESTOCKED,
            self::DISPOSITION_DEFECTIVE,
            self::DISPOSITION_RETURNED_TO_SUPPLIER,
            self::DISPOSITION_SCRAPPED,
        ],
        self::CONDITION_DEFECTIVE => [
            self::DISPOSITION_RETURNED_TO_SUPPLIER,
            self::DISPOSITION_SCRAPPED,
        ],
        self::CONDITION_MISSING => [],
    ];

    /** The serial status a held unit carries, by condition. */
    public const SERIAL_STATUS_NAME = [
        self::CONDITION_MISSING   => 'Missing',
        self::CONDITION_DEFECTIVE => 'Defective',
        self::CONDITION_RETURNED  => 'Returned',
    ];

    protected $table      = 'stock_holds';
    protected $primaryKey = 'hold_id';

    protected $fillable = [
        'incident_id', 'product_id', 'location_id', 'batch_id', 'serial_id',
        'condition', 'quantity', 'status',
        'disposition', 'disposition_note', 'disposed_by', 'disposed_at',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'disposed_at' => 'datetime',
    ];

    /** @var array<string,int> */
    private static array $statusCache = [];

    /**
     * Resolve an inventory status id by name. Mirrors IncidentReport::statusId
     * — install-specific ids are never hardcoded, and they genuinely differ
     * between the dev and test databases.
     */
    public static function inventoryStatusId(string $name): int
    {
        if (isset(self::$statusCache[$name])) {
            return self::$statusCache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown inventory status: {$name}. Run StockHoldStatusSeeder.");
        }

        return self::$statusCache[$name] = (int) $id;
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_id', 'incident_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by', 'user_id');
    }
}
