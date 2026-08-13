<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WarrantyRecord extends Model
{
    use Auditable;

    protected $table      = 'warranty_records';
    protected $primaryKey = 'warranty_id';

    protected $fillable = [
        'issuance_id', 'issuance_detail_id', 'product_id',
        'serial_number', 'customer_id',
        'sale_date', 'warranty_start_date', 'warranty_end_date',
        'warranty_period_months', 'warranty_duration_label',
        'status_id', 'claim_date', 'claimed_by',
        'claim_notes', 'notes',
    ];

    protected $casts = [
        'sale_date'              => 'date',
        'warranty_start_date'    => 'date',
        'warranty_end_date'      => 'date',
        'claim_date'             => 'date',
        'warranty_period_months' => 'integer',
        'status_id'              => 'integer',
    ];

    protected $appends = ['days_remaining', 'is_expired'];

    public const STATUS_ACTIVE  = 33;
    public const STATUS_EXPIRED = 34;
    public const STATUS_CLAIMED = 35;

    public function issuance()
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id')
                    ->with(['applianceDetail.brand']);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function claimedByUser()
    {
        return $this->belongsTo(User::class, 'claimed_by', 'user_id');
    }

    public function claims()
    {
        return $this->hasMany(WarrantyClaim::class, 'warranty_id', 'warranty_id');
    }

    public function issuanceDetail()
    {
        return $this->belongsTo(IssuanceDetail::class, 'issuance_detail_id', 'issuance_detail_id');
    }

    /**
     * Location read-scope via the originating issuance's location.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isCentralized()) {
            return $query;
        }
        $loc = $user->scopedLocationId();
        if ($loc === null) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereHas('issuance', fn ($q) => $q->where('location_id', $loc));
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->warranty_end_date !== null
            && now()->isAfter($this->warranty_end_date);
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->warranty_end_date) return null;
        return (int) now()->diffInDays($this->warranty_end_date, false);
    }

    /**
     * What this warranty ACTUALLY is right now, as opposed to what the stored
     * column last got around to saying.
     *
     * status_id is a cache. It only changed when somebody opened the warranty
     * list, which ran a batch UPDATE before rendering — so a record could sit
     * at "Active" for months after its cover ended, and every other reader
     * believed it. Derived from the date, this cannot go stale, and it does not
     * care whether any scheduled command has ever run.
     *
     * Claimed is checked FIRST and never overridden: it is a real stored fact
     * that no date can imply, and a claimed unit whose cover later lapses is
     * still claimed.
     */
    public function getEffectiveStatusIdAttribute(): int
    {
        if ((int) $this->status_id === self::STATUS_CLAIMED) {
            return self::STATUS_CLAIMED;
        }

        return $this->is_expired ? self::STATUS_EXPIRED : self::STATUS_ACTIVE;
    }

    /**
     * Filter on the DERIVED state, so the list agrees with its own badges.
     *
     * `where('status_id', 33)` would have listed a warranty that lapsed last
     * week under Active, because the column had not been rewritten yet. The
     * date has to be part of the predicate.
     */
    public function scopeWithEffectiveStatus(Builder $query, int $statusId): Builder
    {
        $today = now()->toDateString();

        return match ($statusId) {
            self::STATUS_CLAIMED => $query->where('status_id', self::STATUS_CLAIMED),

            self::STATUS_ACTIVE => $query->where('status_id', self::STATUS_ACTIVE)
                ->where(fn ($q) => $q->whereNull('warranty_end_date')
                    ->orWhereDate('warranty_end_date', '>=', $today)),

            // Either already written down as expired, or still labelled active
            // with the date behind us.
            self::STATUS_EXPIRED => $query->where(fn ($q) => $q
                ->where('status_id', self::STATUS_EXPIRED)
                ->orWhere(fn ($inner) => $inner
                    ->where('status_id', self::STATUS_ACTIVE)
                    ->whereDate('warranty_end_date', '<', $today))),

            default => $query->where('status_id', $statusId),
        };
    }
}
