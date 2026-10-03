<?php

namespace Tests\Feature\Backup;

use App\Models\BackupLog;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A corrupted dump is silent: it still opens, still looks like SQL, still has a
 * plausible size. You find out when a restore dies halfway through — which is
 * always the moment you have no alternative left. These two checks turn
 * "probably fine" into a yes/no answer.
 */
class IntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backups-integrity-' . uniqid());
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $contents): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'dump_' . uniqid() . '.sql';
        file_put_contents($path, $contents);

        return $path;
    }

    private function logFor(string $path, ?string $checksum): BackupLog
    {
        return BackupLog::create([
            'action'          => 'backup',
            'triggered_by'    => 'manual',
            'status'          => 'success',
            'file_path'       => $path,
            'file_name'       => basename($path),
            'checksum_sha256' => $checksum,
        ]);
    }

    // ── completeness trailer ──────────────────────────────────

    public function test_a_mysqldump_trailer_counts_as_complete(): void
    {
        $path = $this->write("CREATE TABLE a (id int);\n-- Dump completed on 2026-10-03  0:30:01\n");

        $this->assertTrue(app(BackupService::class)->dumpLooksComplete($path));
    }

    public function test_the_php_dump_trailer_counts_as_complete(): void
    {
        $path = $this->write("INSERT INTO a VALUES (1);\nSET FOREIGN_KEY_CHECKS=1;\n-- AirKing dump completed\n");

        $this->assertTrue(app(BackupService::class)->dumpLooksComplete($path));
    }

    /**
     * The test above only proves the CHECKER recognises the trailer — it would
     * pass just as happily if runPhpDump() never wrote one. This proves the
     * producer and the checker actually agree, which is the property that makes
     * a truncated PDO dump detectable.
     */
    public function test_a_real_php_dump_is_judged_complete(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'real_php_dump.sql';

        app(BackupService::class)->runPhpDumpTo($path);

        $this->assertFileExists($path);
        $this->assertTrue(
            app(BackupService::class)->dumpLooksComplete($path),
            'runPhpDump() must end with a trailer dumpLooksComplete() accepts'
        );
    }

    public function test_a_dump_with_no_trailer_is_incomplete(): void
    {
        // Exactly what a run killed by a full disk leaves behind.
        $path = $this->write("CREATE TABLE a (id int);\nINSERT INTO a VALUES (1),(2),(");

        $this->assertFalse(app(BackupService::class)->dumpLooksComplete($path));
    }

    public function test_a_trailer_buried_far_from_the_end_does_not_count(): void
    {
        // Guards against matching the phrase inside row data rather than the
        // actual trailer: only the tail of the file is inspected.
        $path = $this->write(
            "-- Dump completed on 2026-01-01\n"
            . str_repeat("INSERT INTO notes VALUES ('x');\n", 200)
        );

        $this->assertFalse(app(BackupService::class)->dumpLooksComplete($path));
    }

    public function test_a_missing_file_is_not_complete(): void
    {
        $this->assertFalse(
            app(BackupService::class)->dumpLooksComplete($this->dir . '/nope.sql')
        );
    }

    public function test_an_empty_file_is_not_complete(): void
    {
        $path = $this->write('');

        $this->assertFalse(app(BackupService::class)->dumpLooksComplete($path));
    }

    // ── checksum ──────────────────────────────────────────────

    public function test_the_checksum_matches_php_hash_file(): void
    {
        $path = $this->write("-- Dump completed\n");

        $this->assertSame(
            hash_file('sha256', $path),
            app(BackupService::class)->checksumFor($path)
        );
    }

    public function test_verification_passes_for_an_untouched_file(): void
    {
        $path = $this->write("-- Dump completed\n");
        $log = $this->logFor($path, hash_file('sha256', $path));

        $this->assertNull(app(BackupService::class)->verifyChecksum($log));
    }

    public function test_verification_fails_once_a_byte_changes(): void
    {
        $path = $this->write("-- Dump completed\n");
        $log = $this->logFor($path, hash_file('sha256', $path));

        file_put_contents($path, "-- Dump completed\n-- tampered\n");

        $message = app(BackupService::class)->verifyChecksum($log);

        $this->assertNotNull($message);
        $this->assertStringContainsString('checksum', strtolower($message));
    }

    public function test_a_row_with_no_recorded_checksum_is_allowed_through(): void
    {
        // Snapshots taken before this migration have no checksum. Refusing to
        // restore them would make an upgrade destroy access to every existing
        // backup, which is worse than the risk it avoids.
        $path = $this->write("-- Dump completed\n");
        $log = $this->logFor($path, null);

        $this->assertNull(app(BackupService::class)->verifyChecksum($log));
    }

    public function test_verification_reports_a_file_that_is_gone(): void
    {
        $path = $this->write("-- Dump completed\n");
        $log = $this->logFor($path, hash_file('sha256', $path));
        unlink($path);

        $this->assertNotNull(app(BackupService::class)->verifyChecksum($log));
    }

    /**
     * The checksum is stored lowercase by hash_file(), but a value that arrived
     * from elsewhere (a hand-written row, an import) may be uppercase. The same
     * bytes must still verify.
     */
    public function test_a_recorded_checksum_in_uppercase_still_verifies(): void
    {
        $path = $this->write("-- Dump completed\n");
        $log = $this->logFor($path, strtoupper(hash_file('sha256', $path)));

        $this->assertNull(app(BackupService::class)->verifyChecksum($log));
    }
}
