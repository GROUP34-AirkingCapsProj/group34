<?php

namespace Tests\Feature\Backup;

use App\Models\Role;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises the real pg_dump/psql subprocesses against a real PostgreSQL
 * database -- the one thing nothing else in this suite does. Every other
 * Backup test either operates on hand-written files (IntegrityTest) or mocks
 * BackupService entirely (ScheduledCommandTest), so without this file the
 * dump/restore engine added for production PostgreSQL has no coverage that
 * would catch it being wrong.
 *
 * Deliberately NOT `use DatabaseTransactions`. pg_dump and psql are external
 * processes on their own database connection, so they only ever see COMMITTED
 * rows -- a row inserted inside an open Laravel test transaction is invisible
 * to them, which would make a test using that trait pass by accident (the
 * subprocess dumps/restores whatever was there before the test even started)
 * rather than by actually exercising a restore.
 *
 * Every test is wrapped in its own full-database snapshot/restore (see
 * setUp/tearDown) rather than cleaning up the specific rows it touched. The
 * first version of this file tracked individual ids instead, and running the
 * FULL backend suite found the gap the hard way: restore() does not revert
 * "the tables this test cares about", it reverts EVERY table the dump
 * includes -- so a test that calls it genuinely rewinds the whole database to
 * an earlier point, which here meant erasing fixture rows (an appliance
 * product, among others) that unrelated test classes running later in the
 * same process needed. 99 unrelated failures elsewhere in the suite were the
 * symptom; the cause was this file not undoing its own restore() calls
 * completely enough. Wrapping every test in a snapshot taken before it runs
 * anything, restored after, makes that the same class of bug as a forgotten
 * database transaction -- impossible by construction rather than something to
 * remember per test.
 *
 * Skips entirely off PostgreSQL: this engine has no MySQL code path to test
 * here, and the fixture setup below is specific to proving the Postgres
 * restore round-trip found and fixed during development -- a foreign key into
 * `users` from the data-excluded `backup_logs` table broke pg_dump's
 * `--clean` dependency ordering, and explicit-value replay of preserved rows
 * needed an identity sequence resync afterward. Both are asserted directly
 * below.
 */
class PostgresEngineTest extends TestCase
{
    private string $dir = '';
    private BackupService $service;
    private string $wrapperSnapshotPath = '';

    /**
     * Gates every line of tearDown(). markTestSkipped() in setUp() throws
     * before any of the real setup below runs -- but PHPUnit still calls
     * tearDown() on a skipped test, and tearDown's whole job is restoring from
     * $wrapperSnapshotPath, which was never taken on a skipped run. Found by
     * running this file against MySQL, where every one of these tests is
     * expected to skip.
     */
    private bool $didSetUp = false;

    /**
     * createBackup()/restore() write real files under resolveStorageDir()
     * (storage/app/backups by default) -- NOT under $this->dir, which is only
     * this test's own scratch space. The database-level wrapper
     * snapshot/restore reverts the backup_logs ROWS those calls create, but
     * nothing about the FILES already written to disk by the time that
     * restore runs; left uncleaned, every test run leaks another dump into
     * real backup storage.
     *
     * A time-window sweep, not tracking each call's return value: restore()
     * also writes an internal `pre_restore` safety-backup file this test never
     * gets a handle to directly, so cleanup has to find files by when they
     * were written, not by which call produced them.
     */
    private float $startedAt = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exercises the PostgreSQL-only pg_dump/psql engine.');
        }

        $this->service = app(BackupService::class);
        $this->dir = storage_path('app/backups-pgengine-' . uniqid());
        mkdir($this->dir, 0755, true);
        $this->startedAt = microtime(true);

        // The whole-database undo button for this test. Taken before the test
        // body runs anything, restored in tearDown -- see the class docblock
        // for why "restore everything" is the only safe cleanup strategy for
        // a file whose subject IS a whole-database restore.
        $this->wrapperSnapshotPath = $this->dir . DIRECTORY_SEPARATOR . 'wrapper_snapshot.sql';
        $this->service->runPgDumpDirect($this->wrapperSnapshotPath);

        $this->didSetUp = true;
    }

    protected function tearDown(): void
    {
        if ($this->didSetUp) {
            // Plain restore, no capture/replay: that dance belongs to the
            // PRODUCTION restore() flow under test, where preserving
            // operational history across a real restore is the point. The
            // wrapper's job is the opposite -- undo everything the test did,
            // full stop. The first version of this tearDown reused
            // capture/replay here too, which tried to resurrect a backup_logs
            // row the test created (referencing a user the test also created)
            // AFTER this restore had already reverted that user away --
            // "foreign key violation: triggered_by_user_id=1 is not present
            // in users". A plain restore reverts backup_logs along with
            // everything else, which is what cleanup actually wants.
            $this->service->runPsqlRestoreDirect($this->wrapperSnapshotPath);

            foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);

            foreach (glob($this->service->resolveStorageDir() . DIRECTORY_SEPARATOR . 'backup_*.sql') ?: [] as $f) {
                if (is_file($f) && (filemtime($f) ?: 0) >= $this->startedAt) {
                    @unlink($f);
                }
            }
        }

        parent::tearDown();
    }

    public function test_pg_dump_produces_a_dump_recognised_as_complete(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'dump.sql';

        $this->service->runPgDumpDirect($path);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        $this->assertTrue(
            $this->service->dumpLooksComplete($path),
            'pg_dump\'s own completion footer must be recognised by dumpLooksComplete()'
        );
        $this->assertStringContainsString(
            'PostgreSQL database dump complete',
            (string) file_get_contents($path)
        );
    }

    public function test_restore_reverts_a_real_change_without_wiping_excluded_tables(): void
    {
        // A real role row, committed -- the thing the restore is supposed to
        // revert. Cleanup is the wrapper snapshot in tearDown, not a manual
        // delete of this row.
        $role = Role::create(['role_name' => 'pg_engine_test_role_' . uniqid(), 'description' => 'x']);
        $roleId = $role->role_id;

        $path = $this->dir . DIRECTORY_SEPARATOR . 'dump.sql';
        $this->service->runPgDumpDirect($path);

        // Corruption made AFTER the snapshot -- restore must remove this.
        DB::table('roles')->where('role_id', $roleId)->update(['description' => 'CORRUPTED']);

        // A backup_logs row -- excluded from the dump, must survive restore
        // intact. This is the exact case the FK-into-users / --clean ordering
        // bug and the identity-sequence-resync bug were both found through.
        $precedingLogCount = DB::table('backup_logs')->count();
        DB::table('backup_logs')->insert([
            'action'       => 'backup',
            'triggered_by' => 'manual',
            'status'       => 'success',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Captured BEFORE the restore, matching restore()'s own ordering
        // exactly -- captureExcludedTableRows() has nothing to capture once
        // runPsqlRestoreDirect has already emptied the table.
        $preserved = $this->captureExcludedRowsViaReflection();
        $this->assertNotEmpty($preserved, 'the row inserted before restore should have been captured');

        $this->service->runPsqlRestoreDirect($path);

        $this->assertSame(
            'x',
            DB::table('roles')->where('role_id', $roleId)->value('description'),
            'restore must revert a row changed after the snapshot was taken'
        );

        // runPsqlRestoreDirect alone (not the full restore() pipeline) is
        // expected to empty backup_logs -- --exclude-table-data recreates it
        // with no rows. Proves the capture/replay this test exercises next is
        // load-bearing, not redundant with pg_dump itself.
        $this->assertSame(0, DB::table('backup_logs')->count());

        // Now prove the layer restore() adds on top actually puts the rows
        // back, AND that the identity sequence is usable afterward -- an
        // insert with no explicit id must not collide with a replayed one.
        $this->replayExcludedRowsViaReflection($preserved);

        $this->assertSame(
            $precedingLogCount + 1,
            DB::table('backup_logs')->count(),
            'replay must restore exactly the rows captured, no more and no fewer'
        );

        $newId = DB::table('backup_logs')->insertGetId([
            'action'       => 'backup',
            'triggered_by' => 'manual',
            'status'       => 'success',
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'log_id');

        $this->assertGreaterThan(
            0,
            $newId,
            'a post-replay insert with no explicit id must succeed -- this is '
            . 'what resyncIdentitySequence() exists to guarantee'
        );
    }

    public function test_restore_full_pipeline_preserves_backup_logs_across_a_real_restore(): void
    {
        $roleRow = Role::create(['role_name' => 'pg_engine_test_role2_' . uniqid(), 'description' => 'x']);

        $user = User::create([
            'email'         => 'pg-engine-test-' . uniqid() . '@example.test',
            'username'      => 'pg_engine_test_' . uniqid(),
            'first_name'    => 'Engine',
            'last_name'     => 'Test',
            'password_hash' => bcrypt('irrelevant'),
            'role_id'       => $roleRow->role_id,
            'is_active'     => true,
        ]);

        $log = $this->service->createBackup($user, 'manual');
        $this->assertSame('success', $log->status, $log->error_message ?? '');

        $logCountBeforeCorruption = DB::table('backup_logs')->count();

        DB::table('roles')->where('role_id', $roleRow->role_id)->update(['description' => 'CORRUPTED']);

        $restoreLog = $this->service->restore($log->fresh(), $user);

        $this->assertSame(
            'success',
            $restoreLog->status,
            'full restore() pipeline must succeed through the FK/--clean ordering '
            . 'issue found in development: ' . ($restoreLog->error_message ?? '')
        );
        $this->assertSame('x', DB::table('roles')->where('role_id', $roleRow->role_id)->value('description'));

        // The restore pipeline itself adds a safety backup row and a restore
        // record row on top of whatever existed -- so this must be strictly
        // more than before, never reset to zero.
        $this->assertGreaterThan(
            $logCountBeforeCorruption,
            DB::table('backup_logs')->count(),
            'backup_logs must survive restore() -- this is the table the whole '
            . 'TABLES_EXCLUDED_FROM_DUMP exclusion exists to protect'
        );

        // Proves the sequence is genuinely usable afterward, not just that no
        // exception surfaced -- a PK collision inside restore()'s own
        // BackupLog::create() calls would otherwise have been swallowed into
        // a generic "failed" status rather than asserted on directly here.
        $probeId = DB::table('backup_logs')->insertGetId([
            'action' => 'backup', 'triggered_by' => 'manual', 'status' => 'success',
            'created_at' => now(), 'updated_at' => now(),
        ], 'log_id');
        $this->assertGreaterThan(0, $probeId);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function captureExcludedRowsViaReflection(): array
    {
        $method = new \ReflectionMethod($this->service, 'captureExcludedTableRows');
        $method->setAccessible(true);

        return $method->invoke($this->service);
    }

    /** @param array<string, list<array<string, mixed>>> $preserved */
    private function replayExcludedRowsViaReflection(array $preserved): void
    {
        $method = new \ReflectionMethod($this->service, 'replayExcludedTableRows');
        $method->setAccessible(true);
        $method->invoke($this->service, $preserved);
    }
}
