<?php

namespace App\Support;

use App\Models\User;

/**
 * Pure authorization rules for realtime channels, mirroring the app's location-scope
 * model: centralized roles (admin=1, inventory_analyst=2, auditor=5) see everything;
 * scoped roles (branch_manager=3, warehouse_personnel=4) see only their own location.
 */
class ChannelAccess
{
    public static function canAccessOrg(User $user): bool
    {
        return $user->isCentralized();
    }

    public static function canAccessLocation(User $user, int $locationId): bool
    {
        if ($user->isCentralized()) {
            return true;
        }
        return $user->scopedLocationId() === $locationId;
    }
}
