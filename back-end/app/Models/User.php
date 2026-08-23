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
        'recovery_email',
        'username',
        'password_hash',
        'must_change_password',
        'phone',
        'avatar_path',
        'address',
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
        'must_change_password' => 'boolean',
    ];

    /**
     * Where password-reset mail goes: the employee's personal inbox, not the
     * company address they are locked out of. Falls back to the login email for
     * users created before recovery_email existed.
     */
    public function getResetMailAddressAttribute(): string
    {
        return $this->recovery_email ?: $this->email;
    }

    /**
     * Notification mail routing. Note that the reset flow does NOT go through
     * this method — it sends a Mailable via Mail::to(), and Laravel consults
     * notification routing only for Notifications. Kept so any future
     * $user->notify() reaches the recovery address too.
     */
    public function routeNotificationForMail($notification)
    {
        return $this->recovery_email ?: $this->email;
    }

    // avatar_url is appended to every User serialization (incl. /api/user, /api/login)
    // so the frontend header/sidebar can render the photo without extra endpoints.
    protected $appends = ['avatar_url'];

    /**
     * Get the password attribute for authentication.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Relative public path for the user's avatar (e.g. "/storage/avatars/x.png"),
     * or null when none is set.
     *
     * Deliberately host-agnostic: the frontend resolves this against the API
     * origin it computes at runtime, so the same value works on localhost, a LAN
     * IP, and mobile. Storage::url() would bake in APP_URL, which is wrong when the
     * app is served by `php artisan serve` on a different host/port than APP_URL.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path
            ? '/storage/' . ltrim($this->avatar_path, '/')
            : null;
    }

    /**
     * Get the role that the user belongs to.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    /**
     * The admin role reaches every feature in the system, so several places ask
     * this question. The string comparison was already duplicated across at
     * least five files; new code asks here instead of copying it a sixth time.
     */
    public function isAdmin(): bool
    {
        return $this->role?->role_name === 'admin';
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
