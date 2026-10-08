<?php

namespace App\Traits;

use App\Models\User;
use Closure;

/**
 * Row-level scoping (ScopesToUserLocation::forUser) filters WHICH rows a user
 * receives. It does nothing to aggregate subqueries attached to those rows —
 * a withSum() over a product's `inventories` relation spans every location
 * unless it is constrained too.
 *
 * That gap is what let the Item Master STOCK column, and the stat cards built
 * from it, report org-wide quantities to a location-scoped user while the rows
 * themselves were correctly filtered.
 */
trait SumsStockForUser
{
    /**
     * Constraint for an `inventories` withSum()/loadSum() subquery.
     *
     * Centralized roles (admin, inventory_analyst, auditor) keep the org-wide
     * total. Scoped roles are limited to their own location; a scoped user with
     * no location assigned sums to zero rather than falling back to org-wide
     * (spec D3 — deny, never widen).
     */
    protected function stockSumForUser(?User $user): Closure
    {
        return static function ($query) use ($user) {
            if ($user === null || $user->isCentralized()) {
                return;
            }

            $locationId = $user->scopedLocationId();

            if ($locationId === null) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->where('inventory.location_id', $locationId);
        };
    }
}
