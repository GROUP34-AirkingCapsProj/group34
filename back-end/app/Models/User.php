<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use SoftDeletes, Auditable;
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'user_id';

    /**
     * Get the route key for the model (for route model binding).
     */
    public function getRouteKeyName(): string
    {
        return 'user_id';
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'username',
        'password_hash',
        'phone',
        'role_id',
        'location_id',
        'status_id',
        'otp_code',
        'otp_expires_at',
        'alerts_last_seen_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'alerts_last_seen_at' => 'datetime',
    ];

    /**
     * Get the password attribute for authentication.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Get the role that the user belongs to.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    /**
     * Get the status of the user.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    /**
     * Get the primary location assigned to the user.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function locations()
    {
        return $this->belongsToMany(
            Location::class,
            'user_locations',
            'user_id',
            'location_id'
        )->where('is_active', 1);
    }

    /**
     * Returns the enforced location_id for this user, or null for admins.
     *
     * Admin (role_id=1) has no location restriction — returns null.
     * All other roles return their primary users.location_id.
     */
    public function getEnforcedLocationId(): ?int
    {
        if ((int) $this->role_id === 1) {
            return null;
        }

        return $this->location_id ? (int) $this->location_id : null;
    }

    /**
     * Returns all location_ids this user is permitted to transact in.
     *
     * Admin        → all active location IDs (no restriction)
     * Branch manager (3) → primary + any rows in user_locations pivot
     * All others   → primary location only
     */
    public function getAllowedLocationIds(): array
    {
        $roleId = (int) $this->role_id;

        if ($roleId === 1) {
            return Location::where('is_active', 1)
                ->pluck('location_id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        if ($roleId === 3) {
            $ids = [];
            if ($this->location_id) {
                $ids[] = (int) $this->location_id;
            }
            $pivot = DB::table('user_locations')
                ->where('user_id', $this->user_id)
                ->pluck('location_id');
            foreach ($pivot as $id) {
                $ids[] = (int) $id;
            }
            return array_values(array_unique($ids));
        }

        return $this->location_id ? [(int) $this->location_id] : [];
    }

    /**
     * Returns true if the user is permitted to transact at the given location.
     */
    public function canTransactAt(int $locationId): bool
    {
        if ((int) $this->role_id === 1) {
            return true;
        }

        return in_array($locationId, $this->getAllowedLocationIds(), true);
    }

    /**
     * Roles that must transact at a single enforced location with no override.
     * Now derived from the scope source of truth: strict == location-scoped == !centralized.
     * (branch_manager=3, warehouse_personnel=4)
     */
    public function hasStrictLocation(): bool
    {
        return ! $this->isCentralized();
    }

    /**
     * Centralized roles see all locations' data and may choose a location on writes.
     * CENTRALIZED = admin(1), inventory_analyst(2), auditor(5). Single source of truth
     * for BOTH read scoping and write enforcement (see spec §5).
     */
    public function isCentralized(): bool
    {
        return in_array((int) $this->role_id, [1, 2, 5], true);
    }

    /**
     * The location_id this user's data is restricted to.
     * Returns null for centralized users (no restriction). For location-scoped
     * roles (branch_manager=3, warehouse_personnel=4) returns users.location_id,
     * which may itself be null when unassigned (callers then deny — spec D3).
     */
    public function scopedLocationId(): ?int
    {
        if ($this->isCentralized()) {
            return null;
        }
        return $this->location_id ? (int) $this->location_id : null;
    }

}
