<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a `forUser` local scope to any model that has a `location_id` column.
 * Centralized users: unfiltered. Scoped users: where location_id = their location.
 * Scoped users with no location: an impossible predicate (empty result) — spec D3.
 */
trait ScopesToUserLocation
{
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isCentralized()) {
            return $query;
        }

        $loc = $user->scopedLocationId();
        if ($loc === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->getTable() . '.location_id', $loc);
    }
}
