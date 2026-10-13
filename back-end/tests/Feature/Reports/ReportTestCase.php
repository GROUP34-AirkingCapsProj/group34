<?php

namespace Tests\Feature\Reports;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sets\SetTestCase;

/**
 * Fixtures for the report suites.
 *
 * Extends SetTestCase for its location/user/product/stock builders. The extra
 * helpers here are the two things reports need that no other suite does:
 * granting report permissions to a READ-SCOPED user (every role that holds
 * report_viewing reads all locations, so scoping is only reachable via an
 * override), and resolving movement type ids by name.
 */
abstract class ReportTestCase extends SetTestCase
{
    /** Movement types are seeded constants; resolve by name so ids may shift. */
    protected function movementTypeId(string $name): int
    {
        return (int) DB::table('transaction_type_lookup')
            ->where('transaction_name', $name)
            ->value('transaction_type_id');
    }

    /**
     * Grant a permission to one user without touching their role.
     *
     * This is the only way to produce a read-scoped user who can run reports:
     * report_viewing is seeded to admin, inventory_analyst, branch_manager and
     * auditor, and all four read every location (User::readsAllLocations).
     * Promoting the user's role to get the permission would widen their read
     * scope with it and test nothing. warehouse_personnel is the role to use.
     */
    protected function grantPermission(User $user, string $feature, string $action): void
    {
        $permission = Permission::firstOrCreate(
            ['feature' => $feature, 'action' => $action],
            ['description' => "{$action} access for {$feature}"]
        );

        UserPermissionOverride::updateOrCreate(
            ['user_id' => $user->user_id, 'permission_id' => $permission->permission_id],
            ['is_granted' => true, 'granted_at' => now()]
        );
    }
}
