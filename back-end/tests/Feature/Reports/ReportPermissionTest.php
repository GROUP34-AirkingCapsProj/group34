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

        // branch_manager holds neither report_viewing.read nor .create in
        // PermissionSeeder::matrix() — a negative case so this test cannot
        // pass if canUser() is broken to return true unconditionally, or if
        // report_viewing.create were accidentally granted to every role.
        $branchManager = $this->makeUserAt($this->makeLocation(), 'branch_manager');

        $this->assertFalse(
            app(PermissionResolver::class)->canUser((int) $branchManager->user_id, 'report_viewing', 'create')
        );
    }
}
