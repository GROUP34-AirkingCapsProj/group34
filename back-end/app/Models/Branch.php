<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Models\User;

/**
 * Branch Proxy Model
 *
 * Provides backward compatibility for code that still references the old `branches` table.
 * Under the hood it queries the unified `locations` table with `location_type = 'branch_office'`.
 */
class Branch extends Model
{
    use Auditable;

    /** The unified table name */
    protected $table = 'locations';

    /** Primary key maps to the unified locations PK */
    protected $primaryKey = 'location_id';

    /** Disable auto-increment detection so Eloquent treats PK as BIGINT */
    public $incrementing = true;

    /** Key type for the primary key */
    protected $keyType = 'int';

    /** Columns that can be mass-assigned via old Branch API */
    protected $fillable = [
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

    /** Casts shared with Location */
    protected $casts = [
        'opening_date' => 'date',
        'capacity'     => 'integer',
    ];

    /**
     * --------------------------------------------------------------------
     * Global scope: only branch_office rows
     * --------------------------------------------------------------------
     */
    protected static function booted(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $builder->where('location_type', 'branch_office');
        });
    }

    /**
     * --------------------------------------------------------------------
     * Route model binding
     * --------------------------------------------------------------------
     */
    public function getRouteKeyName(): string
    {
        return 'location_id';
    }

    /**
     * --------------------------------------------------------------------
     * Custom query builder so that ->orderBy('name') hits location_name
     * --------------------------------------------------------------------
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder {
            public function orderBy($column, $direction = 'asc')
            {
                $column = match ($column) {
                    'name' => 'location_name',
                    'code' => 'location_code',
                    default => $column,
                };
                return parent::orderBy($column, $direction);
            }

            public function orderByDesc($column)
            {
                return $this->orderBy($column, 'desc');
            }
        };
    }

    /**
     * --------------------------------------------------------------------
     * Backward-compatible accessors / mutators
     * --------------------------------------------------------------------
     */

    /** `id` maps to `location_id` */
    public function getIdAttribute(): mixed
    {
        return $this->getAttributeValue('location_id');
    }

    public function setIdAttribute($value): void
    {
        $this->attributes['location_id'] = $value;
    }

    /** `code` maps to `location_code` */
    public function getCodeAttribute(): ?string
    {
        return $this->getAttributeValue('location_code');
    }

    public function setCodeAttribute($value): void
    {
        $this->attributes['location_code'] = $value;
    }

    /** `name` maps to `location_name` */
    public function getNameAttribute(): ?string
    {
        return $this->getAttributeValue('location_name');
    }

    public function setNameAttribute($value): void
    {
        $this->attributes['location_name'] = $value;
    }

    /** `is_active` maps to status_id check (Active = 1) */
    public function getIsActiveAttribute(): bool
    {
        $statusId = $this->getAttributeValue('status_id');
        return $statusId === null || $statusId == 1;
    }

    public function setIsActiveAttribute($value): void
    {
        $this->attributes['status_id'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 2;
    }

    /** `region` maps directly (same column name) */
    public function getRegionAttribute(): ?string
    {
        return $this->getAttributeValue('region');
    }

    public function setRegionAttribute($value): void
    {
        $this->attributes['region'] = $value;
    }

    /** `city` maps directly */
    public function getCityAttribute(): ?string
    {
        return $this->getAttributeValue('city');
    }

    public function setCityAttribute($value): void
    {
        $this->attributes['city'] = $value;
    }

    /** `address` maps directly */
    public function getAddressAttribute(): ?string
    {
        return $this->getAttributeValue('address');
    }

    public function setAddressAttribute($value): void
    {
        $this->attributes['address'] = $value;
    }

    /** `contact_number` maps directly */
    public function getContactNumberAttribute(): ?string
    {
        return $this->getAttributeValue('contact_number');
    }

    public function setContactNumberAttribute($value): void
    {
        $this->attributes['contact_number'] = $value;
    }

    /** `email` maps directly */
    public function getEmailAttribute(): ?string
    {
        return $this->getAttributeValue('email');
    }

    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = $value;
    }

    /** `opening_date` maps directly */
    public function getOpeningDateAttribute(): mixed
    {
        return $this->getAttributeValue('opening_date');
    }

    public function setOpeningDateAttribute($value): void
    {
        $this->attributes['opening_date'] = $value;
    }

    /** `capacity` maps directly */
    public function getCapacityAttribute(): ?int
    {
        return $this->getAttributeValue('capacity');
    }

    public function setCapacityAttribute($value): void
    {
        $this->attributes['capacity'] = $value;
    }

    /**
     * --------------------------------------------------------------------
     * Relationships
     * --------------------------------------------------------------------
     */

    /** Inventories at this branch */
    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'location_id', 'location_id');
    }

    /** Transactions at this branch */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'branch_id', 'location_id');
    }

    /** Users assigned to this branch (legacy: branch_id on users table) */
    public function users()
    {
        return $this->hasMany(User::class, 'location_id', 'location_id');
    }
}
