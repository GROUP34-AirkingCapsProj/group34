<?php

namespace App\Console\Commands;

use App\Models\BackupLog;
use Illuminate\Console\Command;

/**
 * Brings backup history into line with what is actually on disk.
 *
 * The Backup page trusts `status` to decide what can be downloaded or restored,
 * so a row claiming `success` whose file is gone advertises a backup that does
 * not exist. That happens for reasons retention never caused: someone deleted
 * the folder, the snapshot was written by a different install that shared this
 * database, or the configured storage path moved.
 *
 * `missing` is deliberately a separate state from `pruned`. Pruned means
 * retention aged it out on purpose and is unremarkable. Missing means a file was
 * expected and is not there -- an anomaly worth seeing. Collapsing the two would
 * hide the signal.
 *
 * Reversible on purpose: a `missing` row whose file reappears (a drive
 * reconnected, a folder restored) goes back to `success` on the next run.
 * `pruned` rows are never touched -- that deletion was intentional, and some of
 * them keep their path so a human can go looking.
 */
class ReconcileBackupHistory extends Command
{
    protected $signature = 'backup:reconcile
                            {--apply : Write the changes (default is a dry run)}';

    protected $description = 'Flag backup history rows whose snapshot file is missing, and un-flag ones that reappear';

    public function handle(): int
    {
        $rows = BackupLog::query()
            ->where('action', 'backup')
            ->whereIn('status', ['success', 'missing'])
            ->whereNotNull('file_path')
            ->orderBy('log_id')
            ->get();

        $changes = [];

        foreach ($rows as $log) {
            $path      = (string) $log->file_path;
            $reachable = is_file($path);

            $to = match (true) {
                $log->status === 'success' && ! $reachable => 'missing',
                $log->status === 'missing' && $reachable   => 'success',
                default                                    => null,
            };

            if ($to === null) {
                continue;
            }

            $changes[] = [
                'log'  => $log,
                'from' => $log->status,
                'to'   => $to,
                // Distinguishes "the file is gone" from "the whole volume is
                // unreachable" -- an offline NAS or another machine's path.
                'why'  => is_dir(dirname($path)) ? 'file absent' : 'folder unreachable',
            ];
        }

        if ($changes === []) {
            $this->info('Backup history already agrees with disk; nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->table(
            ['log_id', 'file name', 'from', 'to', 'why'],
            array_map(fn ($c) => [
                $c['log']->log_id,
                $c['log']->file_name,
                $c['from'],
                $c['to'],
                $c['why'],
            ], $changes),
        );

        if (! $this->option('apply')) {
            $this->warn(count($changes) . ' row(s) would change. Re-run with --apply to write them.');

            return self::SUCCESS;
        }

        foreach ($changes as $c) {
            // forceFill: `status` is fillable, but this keeps the write explicit
            // about touching nothing else on the row.
            $c['log']->forceFill(['status' => $c['to']])->save();
        }

        $this->info('Reconciled ' . count($changes) . ' row(s).');

        return self::SUCCESS;
    }
}
