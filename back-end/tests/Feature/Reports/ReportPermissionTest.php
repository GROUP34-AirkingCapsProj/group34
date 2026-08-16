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
    }
}
