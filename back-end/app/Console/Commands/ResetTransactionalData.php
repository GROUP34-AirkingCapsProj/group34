<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Empties every transactional table, keeping the item master, the lookups, the
 * users and the access-control rows -- the "start the system fresh without
 * re-entering the catalogue" operation.
 *
 * KEEP is the explicit list; everything else in the schema is cleared. That
 * direction is deliberate. The opposite (an explicit list of tables to clear)
 * silently spares any table added later, so a new transactional table would
 * survive a reset and leave rows pointing at records that no longer exist.
 * Getting it wrong this way round is loud -- a new master table would be
 * emptied, and the dry run lists every table it intends to touch so that is
 * visible before anything happens.
 *
 * Uses TRUNCATE rather than DELETE, without CASCADE. PostgreSQL refuses to
 * TRUNCATE a table that another table references unless that table is in the
 * same statement, so listing all 59 at once makes PostgreSQL itself prove the
 * list is complete: if a kept table referenced a cleared one, this aborts with
 * that table named instead of leaving dangling rows. CASCADE is never used --
 * it would silently empty tables outside the list, including kept ones.
 *
 * RESTART IDENTITY resets every identity sequence, so the first product
 * receipt after a reset is receiving_id 1 rather than 5. A fresh start that
 * began at 4-digit ids would not look fresh.
 *
 *   php artisan data:reset-transactional --connection=pgsql_target --dry-run
 *   php artisan data:reset-transactional --connection=pgsql_target
 *
 * There is no default connection on purpose: this command's whole purpose is
 * destroying data, and it should never be possible to run it against whatever
 * happens to be configured.
 */
class ResetTransactionalData extends Command
{
    protected $signature = 'data:reset-transactional
        {--connection= : Connection to reset (required; no default, deliberately)}
        {--dry-run : Print the plan and row counts, change nothing}
        {--force : Skip the confirmation prompts}';

    protected $description = 'Empty all transactional tables, keeping item master, lookups and users';

    /**
     * Tables that survive the reset.
     *
     * Grouped as the operator thinks about them rather than alphabetically, so
     * a reviewer can check a whole category at a glance.
     */
    public const KEEP = [
        // Item master -- the product catalogue and its per-type detail tables.
        // appliance_details and installation_material_details are subtype rows
        // hanging off products, not transactions.
        'products',
        'appliance_details',
        'installation_material_details',
        'aircon_models',
        'aircon_model_units',
        'product_sets',
        'product_set_lines',
        'product_set_brands',

        // Business partners. Not item master strictly, but master data by the
        // same argument: re-keying them is manual work with nothing gained.
        'suppliers',
        'customers',
        'supplier_product',

        // Lookups.
        'ac_type_lookup',
        'brand_lookup',
        'capacity_lookup',
        'category_lookup',
        'status_lookup',
        'transaction_type_lookup',
        'unit_lookup',
        'variant_lookup',

        // Configuration the application cannot boot without.
        'locations',
        'system_settings',

        // Users and access control. Password hashes live in users, so keeping
        // these means existing passwords keep working even though every session
        // and token is cleared.
        'users',
        'roles',
        'permissions',
        'role_permissions',
        'user_permission_overrides',
        'nav_permission_bundles',
        'nav_bundle_permissions',
        'user_locations',

        // Laravel's own bookkeeping. Clearing this would make the application
        // believe no migration had ever run.
        'migrations',
    ];

    public function handle(): int
    {
        $name = (string) $this->option('connection');

        if ($name === '') {
            $this->error('--connection is required. This command deletes data; it will not guess a target.');

            return self::FAILURE;
        }

        $connection = DB::connection($name);

        if ($connection->getDriverName() !== 'pgsql') {
            $this->error("This command is PostgreSQL-only; '{$name}' is {$connection->getDriverName()}.");

            return self::FAILURE;
        }

        $all = collect($connection->select(
            "select table_name from information_schema.tables
              where table_schema = current_schema() and table_type = 'BASE TABLE'"
        ))->pluck('table_name')->sort()->values()->all();

        $missing = array_diff(self::KEEP, $all);

        if ($missing !== []) {
            $this->error('KEEP names no table on this connection: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $clear = array_values(array_diff($all, self::KEEP));
        sort($clear);

        if ($clear === []) {
            $this->info('Nothing to clear.');

            return self::SUCCESS;
        }

        $this->guardKeepDoesNotReferenceClear($connection, $clear);

        $counts = [];
        $total = 0;

        foreach ($clear as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
            $total += $counts[$table];
        }

        $this->line("Connection : {$name} ({$connection->getDatabaseName()})");
        $this->line('Keeping    : ' . count(self::KEEP) . ' tables');
        $this->line('Clearing   : ' . count($clear) . " tables, {$total} rows");
        $this->newLine();

        foreach ($clear as $table) {
            if ($counts[$table] > 0) {
                $this->line(sprintf('  %-34s %6d', $table, $counts[$table]));
            }
        }

        $empty = count(array_filter($counts, fn ($n) => $n === 0));
        $this->line("  (and {$empty} already-empty tables)");
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("This permanently deletes {$total} rows from {$connection->getDatabaseName()}.");
            $this->warn('Take a backup first if you have not. There is no undo.');

            if (! $this->confirm('Do you have a restorable backup of this database?')) {
                $this->info('Aborted. Run scripts/backup-aiven.ps1 first.');

                return self::FAILURE;
            }

            if (! $this->confirm("Delete {$total} rows from " . $connection->getDatabaseName() . '?')) {
                $this->info('Aborted.');

                return self::FAILURE;
            }
        }

        $before = $this->keepCounts($connection);

        $quoted = implode(', ', array_map(fn ($t) => '"' . $t . '"', $clear));

        $connection->transaction(function () use ($connection, $quoted) {
            $connection->statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY");
        });

        $after = $this->keepCounts($connection);

        return $this->verify($connection, $clear, $before, $after);
    }

    /**
     * A kept table with a foreign key into a cleared one cannot survive the
     * reset: either the TRUNCATE fails, or (with CASCADE, which this command
     * never uses) the kept table is emptied too. Catch it before the prompts
     * rather than as a mid-run error.
     */
    private function guardKeepDoesNotReferenceClear($connection, array $clear): void
    {
        $rows = $connection->select(
            "select tc.table_name as child, ccu.table_name as parent
               from information_schema.table_constraints tc
               join information_schema.constraint_column_usage ccu
                 on ccu.constraint_name = tc.constraint_name
              where tc.constraint_type = 'FOREIGN KEY'
                and tc.table_schema = current_schema()"
        );

        $bad = [];

        foreach ($rows as $row) {
            if (in_array($row->child, self::KEEP, true) && in_array($row->parent, $clear, true)) {
                $bad[] = "{$row->child} -> {$row->parent}";
            }
        }

        if ($bad !== []) {
            throw new RuntimeException(
                'These kept tables reference tables due to be cleared, so the reset would '
                . 'orphan or destroy them: ' . implode(', ', array_unique($bad))
            );
        }
    }

    /** @return array<string, int> */
    private function keepCounts($connection): array
    {
        $counts = [];

        foreach (self::KEEP as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
        }

        return $counts;
    }

    /**
     * Refuses to report success unless the cleared tables are empty AND every
     * kept table still holds exactly what it held before. The second half is
     * the one worth having: it is what catches a CASCADE or a mis-sorted KEEP
     * entry quietly taking the catalogue with it.
     */
    private function verify($connection, array $clear, array $before, array $after): int
    {
        $problems = [];

        foreach ($clear as $table) {
            $n = (int) $connection->table($table)->count();

            if ($n !== 0) {
                $problems[] = "{$table} still holds {$n} rows";
            }
        }

        foreach ($before as $table => $n) {
            if ($after[$table] !== $n) {
                $problems[] = "{$table} changed from {$n} to {$after[$table]} rows";
            }
        }

        if ($problems !== []) {
            $this->newLine();
            $this->error('Reset finished but verification failed:');

            foreach ($problems as $problem) {
                $this->error('  ' . $problem);
            }

            return self::FAILURE;
        }

        $kept = array_sum($before);

        $this->newLine();
        $this->info('Reset complete.');
        $this->line('  cleared : ' . count($clear) . ' tables, now empty, identity sequences restarted');
        $this->line('  kept    : ' . count(self::KEEP) . " tables, {$kept} rows, all counts unchanged");

        return self::SUCCESS;
    }
}
