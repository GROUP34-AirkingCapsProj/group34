<?php

namespace App\Support;

use App\Models\User;

/**
 * Pure authorization rules for realtime channels, mirroring the app's read-scope
 * model: all-location readers (admin=1, inventory_analyst=2, branch_manager=3,
 * auditor=5) see everything; the one read-scoped role, warehouse_personnel=4, sees
 * only its own location.
 *
 * Keyed to readsAllLocations() rather than isCentralized() because a channel
 * carries data to look at, not authority to write — the branch manager's wider
 * read scope applies here, its narrower write scope does not.
 */
class ChannelAccess
{
    public static function canAccessOrg(User $user): bool
    {
        return $user->readsAllLocations();
    }

    public static function canAccessLocation(User $user, int $locationId): bool
    {
        if ($user->readsAllLocations()) {
            return true;
        }
        return $user->scopedLocationId() === $locationId;
    }
}
