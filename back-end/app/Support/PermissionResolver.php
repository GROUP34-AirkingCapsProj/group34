<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PermissionResolver
{
    /**
     * Per-request memo: "feature.action" => permission_id
     *
     * @var array<string, int>
     */
    private array $permissionIdCache = [];

    /**
     * Returns the permission_id for the given feature+action, or null if unknown.
     */
    public function permissionId(string $feature, string $action): ?int
    {
        $key = "{$feature}.{$action}";

        if (array_key_exists($key, $this->permissionIdCache)) {
            return $this->permissionIdCache[$key];
        }

        $row = DB::table('permissions')
            ->where('feature', $feature)
            ->where('action', $action)
            ->value('permission_id');

        $this->permissionIdCache[$key] = $row !== null ? (int) $row : null;

        return $this->permissionIdCache[$key];
    }

    /**
     * Returns whether the given user has the given permission.
     *
     * Resolution order:
     *   1. Unknown permission → false (deny)
     *   2. Active override (revoked_at IS NULL) → return override.is_granted
     *   3. Role default (role_permissions for user's role_id) → return rp.is_granted if row exists
     *   4. No row → false
     */
    public function canUser(int $userId, string $feature, string $action): bool
    {
        $permId = $this->permissionId($feature, $action);

        if ($permId === null) {
            return false;
        }

        // Check for an active user override
        $override = DB::table('user_permission_overrides')
            ->where('user_id', $userId)
            ->where('permission_id', $permId)
            ->whereNull('revoked_at')
            ->value('is_granted');

        if ($override !== null) {
            return (bool) $override;
        }

        // Fall back to role default
        $roleId = DB::table('users')
            ->where('user_id', $userId)
            ->value('role_id');

        if ($roleId === null) {
            return false;
        }

        $roleDefault = DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permId)
            ->value('is_granted');

        if ($roleDefault !== null) {
            return (bool) $roleDefault;
        }

        return false;
    }

    /**
     * Returns the merged effective permissions for the given user.
     *
     * Returns ['feature.action' => bool], role defaults overlaid with active
     * (revoked_at IS NULL) overrides.
     *
     * @return array<string, bool>
     */
    public function effectivePermissionsFor(int $userId): array
    {
        $roleId = DB::table('users')
            ->where('user_id', $userId)
            ->value('role_id');

        $result = [];

        // Load role defaults
        if ($roleId !== null) {
            $roleDefaults = DB::table('role_permissions as rp')
                ->join('permissions as p', 'p.permission_id', '=', 'rp.permission_id')
                ->where('rp.role_id', $roleId)
                ->select('p.feature', 'p.action', 'rp.is_granted')
                ->get();

            foreach ($roleDefaults as $row) {
                $result["{$row->feature}.{$row->action}"] = (bool) $row->is_granted;
            }
        }

        // Overlay active overrides
        $overrides = DB::table('user_permission_overrides as upo')
            ->join('permissions as p', 'p.permission_id', '=', 'upo.permission_id')
            ->where('upo.user_id', $userId)
            ->whereNull('upo.revoked_at')
            ->select('p.feature', 'p.action', 'upo.is_granted')
            ->get();

        foreach ($overrides as $row) {
            $result["{$row->feature}.{$row->action}"] = (bool) $row->is_granted;
        }

        return $result;
    }
}
