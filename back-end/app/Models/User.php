<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use Auditable;
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
        'assigned_location_ids',
        'allowed_panels',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'assigned_location_ids' => 'array',
    ];

    /** null = unrestricted locations; non-empty array = whitelist (warehouse/branch scoping). */
    public function hasFullLocationAccess(): bool
    {
        return $this->assigned_location_ids === null;
    }

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
}
