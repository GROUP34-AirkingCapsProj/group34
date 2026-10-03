<?php

namespace Tests\Feature\Backup;

use App\Models\BackupLog;
use App\Services\BackupService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * History is what the Backup page trusts to decide what can be restored, so a
 * row claiming `success` with no file behind it advertises a backup that does not
 * exist. This install had five such rows, pointing at a different machine's
 * checkout, because the database was copied from another developer's environment.
 */
class ReconcileHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backups-reconcile-' . uniqid());
        mkdir($this->dir, 0755, true);
        // Both the command and the prune floor scan the whole table, so each test
        // must only see rows it created.
        BackupLog::query()->delete();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function row(string $status, ?string $path, bool $createFile = true): BackupLog
    {
        if ($path !== null && $createFile) {
            file_put_contents($path, "-- snapshot\n");
        }

        return BackupLog::create([
            'action'       => 'backup',
            'triggered_by' => 'manual',
            'status'       => $status,
            'file_path'    => $path,
            'file_name'    => $path === null ? null : basename($path),
        ]);
    }

    private function path(string $name): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $name;
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $log = $this->row('success', $this->path('a.sql'));
        unlink($log->file_path);

        $this->artisan('backup:reconcile')
            ->expectsOutputToContain('would change')
            ->assertExitCode(0);

        $this->assertSame('success', $log->refresh()->status, 'a dry run must not write');
    }

    public function test_apply_flags_a_row_whose_file_is_gone(): void
    {
        $log = $this->row('success', $this->path('b.sql'));
        unlink($log->file_path);

        $this->artisan('backup:reconcile', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('missing', $log->refresh()->status);
    }

    public function test_a_row_whose_file_is_present_is_left_alone(): void
    {
        $log = $this->row('success', $this->path('c.sql'));

        $this->artisan('backup:reconcile', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('success', $log->refresh()->status);
    }

    public function test_a_missing_row_goes_back_to_success_when_the_file_reappears(): void
    {
        $log = $this->row('missing', $this->path('d.sql'));

        $this->artisan('backup:reconcile', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('success', $log->refresh()->status, 'a reconnected drive must be recoverable');
    }

    public function test_pruned_rows_are_never_touched(): void
    {
        // Retention keeps the path on a reconciled row, so a pruned row can look
        // exactly like a missing one. That deletion was deliberate.
        $log = $this->row('pruned', $this->path('e.sql'), createFile: false);

        $this->artisan('backup:reconcile', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('pruned', $log->refresh()->status);
    }

    public function test_failed_rows_are_never_touched(): void
    {
        $log = $this->row('failed', null);

        $this->artisan('backup:reconcile', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('failed', $log->refresh()->status);
    }

    public function test_it_reports_an_unreachable_folder_distinctly_from_an_absent_file(): void
    {
        $this->row('success', $this->dir . DIRECTORY_SEPARATOR . 'gone-volume' . DIRECTORY_SEPARATOR . 'f.sql', createFile: false);

        $this->artisan('backup:reconcile')
            ->expectsOutputToContain('folder unreachable')
            ->assertExitCode(0);
    }

    public function test_a_clean_history_reports_nothing_to_do(): void
    {
        $this->row('success', $this->path('g.sql'));

        $this->artisan('backup:reconcile')
            ->expectsOutputToContain('already agrees with disk')
            ->assertExitCode(0);
    }

    /**
     * The floor must guarantee three RESTORABLE snapshots, not three rows. A dead
     * row occupying a protected slot is how this install ended up with one of its
     * three protected rows pointing at another machine's path.
     */
    public function test_the_prune_floor_skips_rows_whose_file_is_gone(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');

        // Four aged snapshots, none a Sunday or the 1st. The newest two are dead.
        $oldest = $this->row('success', $this->path('h1.sql'));
        $keep   = $this->row('success', $this->path('h2.sql'));
        $dead1  = $this->row('success', $this->path('h3.sql'));
        $dead2  = $this->row('success', $this->path('h4.sql'));

        foreach ([$oldest, $keep, $dead1, $dead2] as $r) {
            $r->forceFill(['created_at' => '2026-06-02 00:30:00'])->save();
        }

        $dead1Path = $dead1->file_path;
        $dead2Path = $dead2->file_path;
        unlink($dead1Path);
        unlink($dead2Path);

        $deleted = app(BackupService::class)->pruneOldBackups([
            'keep_daily_days'     => 1,
            'keep_weekly_weeks'   => 1,
            'keep_monthly_months' => 1,
        ]);

        // Only h1 and h2 are reachable, so both are inside the 3-row floor and
        // neither may be deleted. The two dead rows are reconciled, not counted.
        $this->assertSame(0, $deleted);
        $this->assertFileExists($oldest->refresh()->file_path);
        $this->assertFileExists($keep->refresh()->file_path);
        $this->assertSame('pruned', $dead1->refresh()->status);
        $this->assertSame('pruned', $dead2->refresh()->status);

        Carbon::setTestNow();
    }
}
