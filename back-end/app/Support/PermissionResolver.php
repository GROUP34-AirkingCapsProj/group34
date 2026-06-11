<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;

class PermissionResolver
{
    /** Per-request memo of feature.action => permission_id. */
    private array $permIdCache = [];

    public function permissionId(string $feature, string $action): ?int
    {
        $key = $feature . '.' . $action;
        if (! array_key_exists($key, $this->permIdCache)) {
            $this->permIdCache[$key] = Permission::where('feature', $feature)
                ->where('action', $action)
                ->value('permission_id');
        }
        return $this->permIdCache[$key] !== null ? (int) $this->permIdCache[$key] : null;
    }

    public function canUser(int $userId, string $feature, string $action): bool
    {
        $permId = $this->permissionId($feature, $action);
        if ($permId === null) {
            return false; // unknown permission → deny
        }

        $override = UserPermissionOverride::where('user_id', $userId)
            ->where('permission_id', $permId)
            ->whereNull('revoked_at')
            ->first();
        if ($override !== null) {
            return (bool) $override->is_granted;
        }

        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        $rp = RolePermission::where('role_id', $user->role_id)
            ->where('permission_id', $permId)
            ->first();

        return $rp !== null ? (bool) $rp->is_granted : false;
    }

    /**
     * Effective grants for a user: role defaults overlaid with active overrides.
     * Returns ['feature.action' => bool].
     */
    public function effectivePermissionsFor(int $userId): array
    {
        $user = User::find($userId);
        if (! $user) {
            return [];
        }

        $perms = Permission::all()->keyBy('permission_id');
        $effective = [];

        $defaults = RolePermission::where('role_id', $user->role_id)->get();
        foreach ($defaults as $rp) {
            if ($perm = $perms->get($rp->permission_id)) {
                $effective[$perm->feature . '.' . $perm->action] = (bool) $rp->is_granted;
            }
        }

        $overrides = UserPermissionOverride::where('user_id', $userId)->whereNull('revoked_at')->get();
        foreach ($overrides as $ov) {
            if ($perm = $perms->get($ov->permission_id)) {
                $effective[$perm->feature . '.' . $perm->action] = (bool) $ov->is_granted;
            }
        }

        return $effective;
    }
}
