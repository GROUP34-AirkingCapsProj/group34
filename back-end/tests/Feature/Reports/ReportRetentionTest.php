<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use App\Services\ReportRetentionService;
use Illuminate\Support\Facades\Storage;

class ReportRetentionTest extends ReportTestCase
{
    /** Every file this test writes to real storage, deleted in tearDown regardless of pass/fail. */
    private array $writtenPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        parent::tearDown();
    }

    private function makeRun(array $overrides = []): ReportRun
    {
        $user = $this->makeUserAt($this->makeLocation(), 'admin');

        return ReportRun::create(array_merge([
            'report_key'      => 'inventory-summary',
            'title_snapshot'  => 'Inventory Summary Report',
            'format'          => 'pdf',
            'parameters'      => ['location_id' => 1],
            'file_path'       => null,
            'file_name'       => 'retention-test.pdf',
            'file_size_bytes' => 100,
            'row_count'       => 1,
            'generated_by'    => $user->user_id,
            'expires_at'      => now()->addDays(90),
        ], $overrides));
    }

    private function writeRealFile(string $suffix = ''): string
    {
        $path = 'reports/retention-test-' . uniqid() . $suffix . '.pdf';
        Storage::disk('local')->put($path, 'fake pdf content');
        $this->writtenPaths[] = $path;

        return $path;
    }

    public function test_pruning_deletes_the_file_but_keeps_the_row(): void
    {
        $path = $this->writeRealFile();

        $run = $this->makeRun([
            'file_path'  => $path,
            'expires_at' => now()->subDay(),
        ]);

        $prunedCount = (new ReportRetentionService())->prune();

        $this->assertSame(1, $prunedCount);
        $this->assertFalse(Storage::disk('local')->exists($path));

        $fresh = ReportRun::find($run->report_run_id);
        $this->assertNotNull($fresh, 'The report_runs row must survive pruning.');
        $this->assertNotNull($fresh->file_pruned_at);
        $this->assertNull($fresh->file_path);
        $this->assertTrue($fresh->is_expired);
    }

    public function test_a_current_run_is_left_alone(): void
    {
        $path = $this->writeRealFile('-current');

        $run = $this->makeRun([
            'file_path'  => $path,
            'expires_at' => now()->addDays(90),
        ]);

        $prunedCount = (new ReportRetentionService())->prune();

        $this->assertSame(0, $prunedCount);
        $this->assertTrue(Storage::disk('local')->exists($path));

        $fresh = ReportRun::find($run->report_run_id);
        $this->assertNull($fresh->file_pruned_at);
        $this->assertSame($path, $fresh->file_path);
    }

    public function test_an_already_pruned_run_is_not_reprocessed(): void
    {
        $prunedAt = now()->subDays(5)->startOfSecond();

        $run = $this->makeRun([
            'file_path'      => null,
            'expires_at'     => now()->subDays(10),
            'file_pruned_at' => $prunedAt,
        ]);

        $prunedCount = (new ReportRetentionService())->prune();

        $this->assertSame(0, $prunedCount);

        $fresh = ReportRun::find($run->report_run_id);
        $this->assertTrue($prunedAt->equalTo($fresh->file_pruned_at));
    }

    public function test_a_missing_file_does_not_break_the_prune(): void
    {
        $run = $this->makeRun([
            'file_path'  => 'reports/this-file-does-not-exist-' . uniqid() . '.pdf',
            'expires_at' => now()->subDay(),
        ]);

        $prunedCount = (new ReportRetentionService())->prune();

        $this->assertSame(1, $prunedCount);

        $fresh = ReportRun::find($run->report_run_id);
        $this->assertNotNull($fresh->file_pruned_at);
        $this->assertNull($fresh->file_path);
    }

    public function test_the_artisan_command_runs_and_reports_a_count(): void
    {
        $path = $this->writeRealFile('-cmd');

        $this->makeRun([
            'file_path'  => $path,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('reports:prune')
            ->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}
