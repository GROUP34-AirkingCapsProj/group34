<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PermissionResolver
{
    /**
     * Permissions deliberately carved out of the admin structural bypass:
     * PO verification is auditor-only by separation of duties, so the System
     * Administrator does NOT get it automatically — only via a per-user
     * override (see SeederTest + PoVerificationTest).
     *
     * @var array<int, string>  "feature.action"
     */
    private const ADMIN_BYPASS_EXCLUDED = [
        'purchase_order_verification.verify',
    ];

    /**
     * Per-request memo: "feature.action" => permission_id
     *
     * @var array<string, int>
     */
    private array $permissionIdCache = [];

    /**
     * Per-request memo: user_id => is the user a System Administrator.
     *
     * @var array<int, bool>
     */
    private array $adminCache = [];

    /**
     * Whether the user's role is the System Administrator ('admin' slug).
     *
     * Admin is granted full access structurally (not via seeded rows), so
     * permission toggles can never lock the administrator out of any page.
     */
    public function isAdmin(int $userId): bool
    {
        if (array_key_exists($userId, $this->adminCache)) {
            return $this->adminCache[$userId];
        }

        $slug = DB::table('users')
            ->join('roles', 'users.role_id', '=', 'roles.role_id')
            ->where('users.user_id', $userId)
            ->value('roles.role_name');

        return $this->adminCache[$userId] = (strtolower((string) $slug) === 'admin');
    }

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
        // System Administrator always has full access — guarantees toggles can
        // never lock admin out of any page (including the permission editor) —
        // EXCEPT verification actions carved out to auditor-only (admin can still
        // self-grant those via a per-user override, so it's not a hard lockout).
        if ($this->isAdmin($userId) && ! in_array("{$feature}.{$action}", self::ADMIN_BYPASS_EXCLUDED, true)) {
            return true;
        }

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
