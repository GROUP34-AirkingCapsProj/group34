<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;

class ReportRunModelTest extends ReportTestCase
{
    public function test_it_stores_parameters_as_an_array_and_reports_expiry(): void
    {
        $user = $this->makeUserAt($this->makeLocation(), 'admin');

        $run = ReportRun::create([
            'report_key'      => 'inventory-summary',
            'title_snapshot'  => 'Inventory Summary Report',
            'format'          => 'pdf',
            'parameters'      => ['location_id' => 7],
            'file_path'       => 'reports/x.pdf',
            'file_name'       => 'x.pdf',
            'file_size_bytes' => 1234,
            'row_count'       => 9,
            'generated_by'    => $user->user_id,
            'expires_at'      => now()->addDays(90),
        ]);

        $fresh = ReportRun::find($run->report_run_id);

        $this->assertSame(['location_id' => 7], $fresh->parameters);
        $this->assertFalse($fresh->is_expired);

        $fresh->update(['file_pruned_at' => now(), 'file_path' => null]);

        $this->assertTrue($fresh->fresh()->is_expired);
    }
}
