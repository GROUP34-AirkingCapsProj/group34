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

    public const REASON_NOT_FOUND     = 'not_found';
    public const REASON_DAMAGED       = 'damaged';
    public const REASON_WRONG_TYPE    = 'wrong_type';
    public const REASON_ZONE_MISMATCH = 'zone_mismatch';

    public const REASONS = [
        self::REASON_NOT_FOUND,
        self::REASON_DAMAGED,
        self::REASON_WRONG_TYPE,
        self::REASON_ZONE_MISMATCH,
    ];

    protected $table      = 'incident_reports';
    protected $primaryKey = 'incident_id';

    protected $fillable = [
        'incident_number', 'source_type', 'source_id', 'location_id',
        'product_id', 'batch_id', 'serial_id', 'expected_zone', 'actual_zone',
        'reason', 'quantity', 'narrative', 'photo_path', 'reported_by',
        'status_id', 'reviewed_by', 'reviewed_at',
        'adjustment_id', 'correction_id', 'resolution_note',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'reviewed_at' => 'datetime',
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
}
