<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a backup survive the deploy that follows it.
 *
 * Render's container disk is wiped on every deploy (docs/DEPLOY-RENDER.md
 * already documents this for uploaded files; it applies identically here), so
 * a locally-written backup is real protection only within a single deploy
 * cycle -- after the next one, the row in backup_logs still exists but the
 * file it points at does not. These two columns are how BackupService records
 * that a copy also exists somewhere that deploy does not touch.
 *
 * Both nullable, both unset on every row written before this migration and on
 * every row written while BACKUP_REMOTE_DISK is unconfigured: remote sync is
 * best-effort and additive, not a requirement local backups suddenly gained.
 * A null remote_disk means exactly what it always meant for that row -- "this
 * backup lives on local disk only" -- nothing about existing rows' meaning
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            // Which Storage disk (config/filesystems.php) holds the remote
            // copy -- 's3' or similar. Not hardcoded to one disk name: letting
            // the row record it is what lets BACKUP_REMOTE_DISK change later
            // without old rows lying about where their copy lives.
            $table->string('remote_disk', 50)->nullable()->after('file_path');

            // Path within that disk, which can legitimately differ from
            // file_name (a prefix may be added, e.g. "backups/2026/10/...").
            $table->string('remote_path', 500)->nullable()->after('remote_disk');
        });
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropColumn(['remote_disk', 'remote_path']);
        });
    }
};
