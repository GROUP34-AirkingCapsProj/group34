<?php

namespace Tests\Feature\Reports;

use App\Models\Permission;
use App\Support\PermissionResolver;

class ReportPermissionTest extends ReportTestCase
{
    public function test_report_viewing_create_exists_and_is_held_by_analysts(): void
    {
        $this->assertNotNull(
            Permission::where('feature', 'report_viewing')->where('action', 'create')->first(),
            'report_viewing.create must exist — generating a report is a write.'
        );

        $analyst = $this->makeUserAt($this->makeLocation(), 'inventory_analyst');

        $this->assertTrue(
            app(PermissionResolver::class)->canUser((int) $analyst->user_id, 'report_viewing', 'create')
        );

        // The branch manager holds both read and create: it generates reports as
        // the admin's deputy, and every report narrows its rows by the caller's
        // read scope, which for this role spans every location.
        $branchManager = $this->makeUserAt($this->makeLocation(), 'branch_manager');

        $this->assertTrue(
            app(PermissionResolver::class)->canUser((int) $branchManager->user_id, 'report_viewing', 'read')
        );
        $this->assertTrue(
            app(PermissionResolver::class)->canUser((int) $branchManager->user_id, 'report_viewing', 'create')
        );

        // warehouse_personnel holds neither — a negative case so this test cannot
        // pass if canUser() is broken to return true unconditionally, or if
        // report_viewing.create were accidentally granted to every role.
        $warehouse = $this->makeUserAt($this->makeLocation(), 'warehouse_personnel');

        $this->assertFalse(
            app(PermissionResolver::class)->canUser((int) $warehouse->user_id, 'report_viewing', 'create')
        );
    }
}
