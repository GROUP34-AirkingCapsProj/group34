<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

/**
 * Location (unified physical place)
 * Table: locations
 * Replaces: branches + warehouses
 * location_type: warehouse | showroom | branch_office
 */
class Location extends Model
{
    use Auditable;
    protected $table = 'locations';
    protected $primaryKey = 'location_id';

    public function getRouteKeyName(): string
    {
        return 'location_id';
    }

    protected $fillable = [
        'parent_location_id',
        'location_code',
        'location_name',
        'location_type',
        'address',
        'city',
        'province',
        'region',
        'contact_number',
        'email',
        'manager_name',
        'capacity',
        'opening_date',
        'status_id',
    ];

    protected $casts = [
        'opening_date' => 'date',
        'capacity'     => 'integer',
    ];

    protected $appends = ['name'];

    public function getNameAttribute(): ?string
    {
        return $this->location_name;
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_location_id', 'location_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_location_id', 'location_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'location_id', 'location_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'location_id', 'location_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'location_id', 'location_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'location_id', 'location_id');
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class, 'location_id', 'location_id');
    }

    public function receivings(): HasMany
    {
        return $this->hasMany(Receiving::class, 'location_id', 'location_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class, 'location_id', 'location_id');
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_location_id', 'location_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_location_id', 'location_id');
    }
}
