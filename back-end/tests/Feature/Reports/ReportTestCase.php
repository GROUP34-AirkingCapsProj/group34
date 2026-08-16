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
 * granting report permissions to a LOCATION-SCOPED user (roles that hold
 * report_viewing are all centralized, so scoping is only reachable via an
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
     * This is the only way to produce a location-scoped user who can run
     * reports: report_viewing is seeded to admin/inventory_analyst/auditor,
     * all three of which are centralized. Promoting the user's role to get
     * the permission would also make them centralized and test nothing.
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
