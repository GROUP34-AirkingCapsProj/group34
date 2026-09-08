<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Copies the business data from the local MySQL database into the deployed
 * PostgreSQL one.
 *
 * `migrate` creates the 86-table baseline and the reference data the app cannot
 * boot without, and stops there — products, inventory, users and history stay on
 * the developer machine. This command moves them.
 *
 *   php artisan data:transfer --dry-run     # print the plan, write nothing
 *   php artisan data:transfer               # do it
 *   php artisan data:transfer --sql=out.sql # write a script instead of connecting
 *
 * Reads the MySQL connection (DB_*) and writes the pgsql_target connection
 * (PG_*), so both databases are open at once and local development is never
 * reconfigured. See docs/DATA-TRANSFER.md for the reasoning behind each of the
 * five hard parts below.
 *
 * Why not pgloader: at ~3,000 rows its throughput advantage is worth nothing,
 * and its casting rules would be a second place to get the same conversions
 * wrong. Everything here reuses what ExportPostgresSchema already established
 * about this schema.
 *
 * The whole load runs in ONE transaction that is only committed once the
 * verification pass agrees. A failure leaves the target exactly as it was.
 */
class TransferDataToPostgres extends Command
{
    protected $signature = 'data:transfer
        {--from=mysql : Source MySQL/MariaDB connection}
        {--to=pgsql_target : Destination PostgreSQL connection}
        {--dry-run : Print the plan and row counts without writing anything}
        {--table=* : Move only these tables (debugging; parents must already be present)}
        {--chunk=500 : Rows per INSERT statement}
        {--sql= : Write the load to this .sql file instead of connecting to the target}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Copy business data from local MySQL into the deployed PostgreSQL database';

    /**
     * Tables that are deliberately not copied.
     *
     * The first five are transient by nature — and copying live sessions or
     * tokens to a host with a different APP_KEY produces cookies that cannot be
     * decrypted, so everyone would have to log in again anyway.
     *
     * `migrations` is different and more dangerous: the target's copy was written
     * by the baseline load and is what tells Laravel the historical migrations
     * are already applied. Overwriting it with this machine's copy would be
     * harmless today and wrong the moment the two drift.
     */
    private const SKIP_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    private Connection $source;

    private Connection $target;

    private string $schema;

    /** Columns to cast to boolean, as a "table.column" => true set. */
    private array $booleanColumns = [];

    /** Columns landing in jsonb, as a "table.column" => true set. */
    private array $jsonColumns = [];

    /**
     * FK columns nulled during the first pass and filled in by a second.
     *
     * table => list of column names. Populated for self-referencing tables
     * (locations.parent_location_id, transfers.transfer_request_id) and for any
     * cycle the topological sort cannot otherwise break.
     */
    private array $deferredColumns = [];

    /** table => primary-key column, used by the second pass. */
    private array $primaryKeys = [];

    private array $notes = [];

    private bool $targetReachable = true;

    public function handle(): int
    {
        $this->source = DB::connection($this->option('from'));
        $this->target = DB::connection($this->option('to'));

        if (! in_array($this->source->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error("Source [{$this->option('from')}] is not MySQL/MariaDB.");

            return self::FAILURE;
        }

        if ($this->target->getDriverName() !== 'pgsql') {
            $this->error("Destination [{$this->option('to')}] is not PostgreSQL.");

            return self::FAILURE;
        }

        try {
            $this->source->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot reach the source database: '.$e->getMessage());
            $this->line('  Check DB_* in back-end/.env, and that MySQL is running.');

            return self::FAILURE;
        }

        // The plan is derived entirely from the source, so an unreachable target
        // is only fatal once there is something to write. Reporting it here
        // rather than after the plan keeps a broken PG_* from looking like a
        // problem with the data. --sql never connects at all.
        try {
            if (! $this->option('sql')) {
                $this->target->getPdo();
            }
        } catch (Throwable $e) {
            if (! $this->option('dry-run')) {
                $this->error('Cannot reach the target database: '.$e->getMessage());
                $this->line('  Check PG_* in back-end/.env (host, port, PG_SSLMODE=require).');

                return self::FAILURE;
            }

            $this->targetReachable = false;
            $this->notes[] = 'target unreachable: '.$e->getMessage();
        }

        $this->schema = $this->source->getDatabaseName();

        $tables = $this->tablesToMove();

        if ($tables === []) {
            $this->error('No tables to move.');

            return self::FAILURE;
        }

        $this->readColumnMetadata($tables);
        $order = $this->loadOrder($tables);
        $counts = $this->sourceCounts($order);

        $this->printPlan($order, $counts);

        if ($this->option('dry-run')) {
            $this->newLine();

            foreach ($this->notes as $note) {
                $this->warn('  note: '.$note);
            }

            $this->info('Dry run — nothing was written.');

            return $this->targetReachable ? self::SUCCESS : self::FAILURE;
        }

        if ($path = $this->option('sql')) {
            return $this->writeSqlFile($path, $order, $counts);
        }

        if (! $this->confirmTarget(array_sum($counts))) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        return $this->transfer($order, $counts);
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function tablesToMove(): array
    {
        $all = array_map(fn ($r) => $r->TABLE_NAME, $this->source->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$this->schema, 'BASE TABLE']
        ));

        $all = array_values(array_diff($all, self::SKIP_TABLES));

        if ($only = $this->option('table')) {
            if ($unknown = array_diff($only, $all)) {
                throw new RuntimeException('Unknown or skipped table(s): '.implode(', ', $unknown));
            }

            return array_values(array_intersect($all, $only));
        }

        return $all;
    }

    /**
     * Works out which columns need converting, and each table's primary key.
     *
     * tinyint(1) is Laravel's boolean(): PDO hands back the integer 0 or 1, and
     * PostgreSQL rejects both for a boolean column. longtext columns listed in
     * ExportPostgresSchema::JSON_COLUMNS became jsonb in the baseline. Every
     * other type crosses unchanged — the schema has no binary columns, and
     * sql_mode includes NO_ZERO_DATE so the '0000-00-00' trap cannot occur.
     */
    private function readColumnMetadata(array $tables): void
    {
        $rows = $this->source->select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, EXTRA
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
            [$this->schema]
        );

        $json = array_flip(ExportPostgresSchema::JSON_COLUMNS);

        foreach ($rows as $row) {
            $key = $row->TABLE_NAME.'.'.$row->COLUMN_NAME;

            if (strtolower($row->COLUMN_TYPE) === 'tinyint(1)') {
                $this->booleanColumns[$key] = true;
            }

            if (isset($json[$key]) || strtolower($row->COLUMN_TYPE) === 'json') {
                $this->jsonColumns[$key] = true;
            }

            if (str_contains(strtolower($row->EXTRA), 'auto_increment')) {
                $this->primaryKeys[$row->TABLE_NAME] = $row->COLUMN_NAME;
            }
        }

        foreach ($tables as $table) {
            if (! isset($this->primaryKeys[$table])) {
                $pk = $this->source->select(
                    'SELECT COLUMN_NAME FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
                     ORDER BY SEQ_IN_INDEX',
                    [$this->schema, $table, 'PRIMARY']
                );

                if (count($pk) === 1) {
                    $this->primaryKeys[$table] = $pk[0]->COLUMN_NAME;
                }
            }
        }
    }

    /**
     * @return array<string, array<string, list<string>>> table => parent => local columns
     */
    private function foreignKeys(array $tables): array
    {
        $rows = $this->source->select(
            'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->schema]
        );

        $wanted = array_flip($tables);
        $graph = array_fill_keys($tables, []);

        foreach ($rows as $row) {
            if (! isset($wanted[$row->TABLE_NAME], $wanted[$row->REFERENCED_TABLE_NAME])) {
                continue;
            }

            $graph[$row->TABLE_NAME][$row->REFERENCED_TABLE_NAME][] = $row->COLUMN_NAME;
        }

        return $graph;
    }

    /**
     * Orders the tables so every row is inserted after the rows it references.
     *
     * PostgreSQL will not let this be avoided: SET CONSTRAINTS ALL DEFERRED only
     * applies to constraints declared DEFERRABLE (ours are not), and DISABLE
     * TRIGGER ALL needs superuser, which Aiven's avnadmin is not.
     *
     * Self-references are removed from the graph up front and their columns
     * deferred to a second pass. If what remains still contains a cycle, one
     * nullable FK column inside it is deferred the same way — 171 of the 223 FK
     * columns are nullable, so there is almost always a way through. If there is
     * not, this aborts rather than guessing.
     *
     * @return list<string>
     */
    private function loadOrder(array $tables): array
    {
        $graph = $this->foreignKeys($tables);

        foreach ($graph as $table => $parents) {
            if (isset($parents[$table])) {
                $this->deferColumns($table, $parents[$table], 'self-referencing');
                unset($graph[$table][$table]);
            }
        }

        $order = [];
        $remaining = $graph;

        while ($remaining !== []) {
            $ready = [];

            foreach ($remaining as $table => $parents) {
                if (array_diff_key($parents, $remaining) === $parents) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                $this->breakCycle($remaining);

                continue;
            }

            sort($ready);

            foreach ($ready as $table) {
                $order[] = $table;
                unset($remaining[$table]);
            }
        }

        return $order;
    }

    /**
     * Defers one nullable FK column inside a cycle so the sort can continue.
     *
     * @param  array<string, array<string, list<string>>>  $remaining
     */
    private function breakCycle(array &$remaining): void
    {
        foreach ($remaining as $table => $parents) {
            foreach ($parents as $parent => $columns) {
                if (! isset($remaining[$parent])) {
                    continue;
                }

                $nullable = array_values(array_filter(
                    $columns,
                    fn ($c) => $this->isNullable($table, $c)
                ));

                if ($nullable === []) {
                    continue;
                }

                $this->deferColumns($table, $nullable, "cycle with {$parent}");

                $remaining[$table][$parent] = array_values(array_diff($columns, $nullable));

                if ($remaining[$table][$parent] === []) {
                    unset($remaining[$table][$parent]);
                }

                return;
            }
        }

        throw new RuntimeException(
            'Circular foreign keys with no nullable column to defer, among: '
            .implode(', ', array_keys($remaining))
        );
    }

    private function deferColumns(string $table, array $columns, string $reason): void
    {
        foreach ($columns as $column) {
            if (! $this->isNullable($table, $column)) {
                throw new RuntimeException(
                    "{$table}.{$column} is {$reason} but NOT NULL, so it cannot be "
                    .'filled in by a second pass. This needs handling by hand.'
                );
            }

            if (! isset($this->primaryKeys[$table])) {
                throw new RuntimeException(
                    "{$table} needs a deferred column ({$column}) but has no single-column "
                    .'primary key to update it by.'
                );
            }

            $this->deferredColumns[$table][] = $column;
            $this->notes[] = "{$table}.{$column} deferred to a second pass ({$reason})";
        }
    }

    private function isNullable(string $table, string $column): bool
    {
        $row = $this->source->selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->schema, $table, $column]
        );

        return ($row->IS_NULLABLE ?? 'NO') === 'YES';
    }

    /** @return array<string, int> */
    private function sourceCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = $this->source->table($table)->count();
        }

        return $counts;
    }

    private function printPlan(array $order, array $counts): void
    {
        $nonEmpty = array_filter($counts);

        $this->newLine();
        $this->info("Source: {$this->source->getDriverName()} [{$this->schema}]");

        if ($path = $this->option('sql')) {
            $this->info("Target: {$path} (a script; nothing is connected to)");
        } else {
            $this->info('Target: pgsql ['.$this->target->getConfig('database').'] on '
                .$this->target->getConfig('host')
                .($this->targetReachable ? '' : '  — UNREACHABLE'));
        }

        $this->newLine();

        $this->line('Load order ('.count($order).' tables, '
            .count($nonEmpty).' with rows, '.array_sum($counts).' rows total):');

        foreach ($order as $i => $table) {
            $count = $counts[$table];
            $flags = [];

            if ($deferred = $this->deferredColumns[$table] ?? []) {
                $flags[] = 'defer '.implode(', ', $deferred);
            }

            if ($booleans = $this->columnsOf($this->booleanColumns, $table)) {
                $flags[] = count($booleans).' bool';
            }

            if ($jsons = $this->columnsOf($this->jsonColumns, $table)) {
                $flags[] = count($jsons).' jsonb';
            }

            $this->line(sprintf(
                '  %2d. %-40s %6s  %s',
                $i + 1,
                $table,
                $count === 0 ? '-' : number_format($count),
                $flags ? '('.implode('; ', $flags).')' : ''
            ));
        }

        $this->newLine();
        $this->line('Not moved: '.implode(', ', self::SKIP_TABLES));
    }

    /** @return list<string> */
    private function columnsOf(array $set, string $table): array
    {
        $prefix = $table.'.';

        return array_values(array_map(
            fn ($k) => substr($k, strlen($prefix)),
            array_filter(array_keys($set), fn ($k) => str_starts_with($k, $prefix))
        ));
    }

    private function confirmTarget(int $rows): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->newLine();
        $this->warn('This EMPTIES every table listed above on '
            .$this->target->getConfig('host').' and reloads it from this machine.');

        return $this->confirm('Have you taken a backup of the target database?', false)
            && $this->confirm("Move {$rows} rows now?", false);
    }

    // ------------------------------------------------------------------
    // Transfer
    // ------------------------------------------------------------------

    private function transfer(array $order, array $counts): int
    {
        if ($missing = $this->preflight($order)) {
            $this->newLine();
            $this->error('The target schema does not match the source:');

            foreach ($missing as $problem) {
                $this->line('  - '.$problem);
            }

            $this->newLine();
            $this->line('The baseline was generated from an older version of this schema.');
            $this->line('Regenerate it (php artisan schema:export-pgsql), redeploy, and retry.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->target->beginTransaction();

        try {
            $this->clear($order);
            $this->load($order, $counts);
            $this->fillDeferredColumns();
            $this->resyncSequences($order);

            $failures = $this->verify($order, $counts);

            if ($failures !== []) {
                $this->target->rollBack();

                $this->newLine();
                $this->error('Verification failed — rolled back, the target is unchanged:');

                foreach ($failures as $failure) {
                    $this->line('  - '.$failure);
                }

                return self::FAILURE;
            }

            $this->target->commit();
        } catch (Throwable $e) {
            $this->target->rollBack();

            $this->newLine();
            $this->error('Transfer failed — rolled back, the target is unchanged.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Transferred '.number_format(array_sum($counts)).' rows into '
            .count(array_filter($counts)).' tables. Verified and committed.');

        foreach ($this->notes as $note) {
            $this->line('  note: '.$note);
        }

        $this->newLine();
        $this->line('Log in on the deployed site with your real account — not the seeder\'s.');
        $this->line('Do NOT also run AdminUserSeeder: its password is published in the repo.');

        return self::SUCCESS;
    }

    /**
     * Checks the target has every table and column the load is about to write.
     *
     * The baseline schema was generated from this same MySQL database, so the
     * two agree — until a migration is written and the baseline is not
     * regenerated. Catching that here turns a mid-transaction "column does not
     * exist" into one sentence naming what drifted.
     *
     * @return list<string>
     */
    private function preflight(array $order): array
    {
        $problems = [];
        $schema = $this->target->getSchemaBuilder();

        foreach ($order as $table) {
            if (! $schema->hasTable($table)) {
                $problems[] = "table {$table} is missing from the target";

                continue;
            }

            $sourceColumns = $this->source->getSchemaBuilder()->getColumnListing($table);
            $missing = array_diff($sourceColumns, $schema->getColumnListing($table));

            foreach ($missing as $column) {
                $problems[] = "{$table}.{$column} is missing from the target";
            }
        }

        return $problems;
    }

    /**
     * Empties the target tables in reverse load order.
     *
     * 16 of them already hold the reference data the baseline seeded, so this is
     * a reload rather than an append — local is the source of truth, and merging
     * two versions of a lookup table by hand is how you end up with duplicate
     * brands. Emptying all of them (not just those 16) is also what makes a
     * second run after a failed first one safe.
     *
     * DELETE, not TRUNCATE: TRUNCATE takes an ACCESS EXCLUSIVE lock and, with
     * CASCADE, would silently empty tables outside this list.
     */
    private function clear(array $order): void
    {
        $this->line('Emptying target tables…');

        foreach (array_reverse($order) as $table) {
            $this->target->table($table)->delete();
        }
    }

    private function load(array $order, array $counts): void
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $bar = $this->output->createProgressBar(array_sum($counts));
        $bar->start();

        foreach ($order as $table) {
            if ($counts[$table] === 0) {
                continue;
            }

            $deferred = array_flip($this->deferredColumns[$table] ?? []);
            $key = $this->primaryKeys[$table] ?? null;

            $query = $this->source->table($table);

            // Ordered by primary key so a chunked read is stable, and so a
            // failure names a row that can be found again.
            $key ? $query->orderBy($key) : $query->orderByRaw('1');

            $query->chunk($chunk, function ($rows) use ($table, $deferred, $bar) {
                $insert = [];

                foreach ($rows as $row) {
                    $values = [];

                    foreach ((array) $row as $column => $value) {
                        $values[$column] = isset($deferred[$column])
                            ? null
                            : $this->convert($table, $column, $value);
                    }

                    $insert[] = $values;
                }

                $this->target->table($table)->insert($insert);
                $bar->advance(count($insert));
            });
        }

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Converts one MySQL value into something PostgreSQL will accept.
     *
     * Everything arrives from PDO as a string or null. Integers, decimals and
     * timestamps are cast by PostgreSQL from their string form, so only the two
     * types where the *representation* differs need touching.
     */
    private function convert(string $table, string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $key = $table.'.'.$column;

        if (isset($this->booleanColumns[$key])) {
            return (int) $value === 1;
        }

        if (isset($this->jsonColumns[$key])) {
            // jsonb rejects the empty string. A column that is empty rather than
            // absent means "no value", which is what NULL is for.
            return trim((string) $value) === '' ? null : $value;
        }

        return $value;
    }

    /** Second pass for the columns nulled during the load. */
    private function fillDeferredColumns(): void
    {
        if ($this->deferredColumns === []) {
            return;
        }

        foreach ($this->deferredColumns as $table => $columns) {
            $key = $this->primaryKeys[$table];

            foreach ($columns as $column) {
                $rows = $this->source->table($table)
                    ->whereNotNull($column)
                    ->pluck($column, $key);

                foreach ($rows as $id => $value) {
                    $this->target->table($table)
                        ->where($key, $id)
                        ->update([$column => $value]);
                }

                $this->line("Filled {$table}.{$column} on ".$rows->count().' rows.');
            }
        }
    }

    /**
     * Advances every identity sequence past the rows just inserted.
     *
     * GENERATED BY DEFAULT AS IDENTITY does not advance when an explicit id is
     * supplied, so after loading 338 products with ids 1-338 the sequence still
     * reads 1 and the next product a user creates collides on the primary key.
     * That failure would surface weeks later, in front of a user, which is why
     * verify() asserts this rather than trusting it.
     */
    private function resyncSequences(array $order): void
    {
        $this->line('Resynchronising identity sequences…');

        foreach ($order as $table) {
            if (! $sequence = $this->sequenceFor($table)) {
                continue;
            }

            $column = $this->primaryKeys[$table];
            $max = $this->target->table($table)->max($column);

            // The third argument is is_called: false on an empty table so the
            // next value is 1 rather than 2.
            $this->target->select('SELECT setval(?, ?, ?)', [
                $sequence,
                $max ?? 1,
                $max !== null,
            ]);
        }
    }

    private function sequenceFor(string $table): ?string
    {
        if (! $column = $this->primaryKeys[$table] ?? null) {
            return null;
        }

        return $this->target->selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence',
            [$table, $column]
        )->sequence ?? null;
    }

    // ------------------------------------------------------------------
    // SQL script output
    // ------------------------------------------------------------------

    /**
     * Writes the whole load to a file instead of performing it.
     *
     * For when this machine cannot reach the target at all — a network that
     * accepts the TCP connection and then swallows the PostgreSQL handshake,
     * which no amount of correct configuration fixes. The script is the
     * deliverable: it can be run from anywhere with psql, by anyone, and this
     * machine never needs to talk to Aiven.
     *
     *   php artisan data:transfer --sql=airking-data.sql
     *   psql "postgres://avnadmin:PASSWORD@HOST:PORT/defaultdb?sslmode=require" \
     *       -f airking-data.sql
     *
     * The script does everything the live path does and in the same order —
     * empty, load, fill deferred columns, resynchronise sequences, verify —
     * inside one transaction. The verification is a DO block that RAISEs, so a
     * mismatch aborts the transaction rather than reporting success. What it
     * cannot do is check foreign keys itself; PostgreSQL enforces those on
     * insert, so a violation aborts the transaction anyway.
     *
     * The file contains real data, including password hashes and customer
     * records. It is not a repository artefact. Delete it once it has been run.
     */
    private function writeSqlFile(string $path, array $order, array $counts): int
    {
        $absolute = $this->absolutePath($path);

        if (! is_dir(dirname($absolute))) {
            $this->error('No such directory: '.dirname($absolute));

            return self::FAILURE;
        }

        // LF endings and UTF-8 without a BOM: psql reads this on a Linux host,
        // and a BOM would be parsed as part of the first statement.
        $handle = fopen($absolute, 'wb');

        if ($handle === false) {
            $this->error('Could not write to '.$absolute);

            return self::FAILURE;
        }

        $write = function (string $sql) use ($handle): void {
            fwrite($handle, str_replace("\r\n", "\n", $sql));
        };

        $write($this->sqlHeader($order, $counts));

        // Reverse load order, so a child is emptied before its parent.
        $write("--\n-- Empty the tables being reloaded.\n--\n");

        foreach (array_reverse($order) as $table) {
            $write('DELETE FROM '.$this->q($table).";\n");
        }

        $write("\n");

        $bar = $this->output->createProgressBar(array_sum($counts));
        $bar->start();

        foreach ($order as $table) {
            if ($counts[$table] === 0) {
                continue;
            }

            $write("--\n-- {$table}: ".number_format($counts[$table])." rows\n--\n");
            $this->writeInserts($write, $table, $bar);
            $write("\n");
        }

        $bar->finish();
        $this->newLine(2);

        $write($this->sqlDeferredUpdates());
        $write($this->sqlSequences($order));
        $write($this->sqlAssertions($order, $counts));
        $write("COMMIT;\n");

        fclose($handle);

        $this->info('Wrote '.$absolute);
        $this->line('  '.number_format(filesize($absolute) / 1024, 1).' KiB, '
            .number_format(array_sum($counts)).' rows, '.count($order).' tables');

        foreach ($this->notes as $note) {
            $this->line('  note: '.$note);
        }

        $this->newLine();
        $this->warn('This file contains real data, including password hashes.');
        $this->line('  Do not commit it. Delete it once it has been loaded.');
        $this->newLine();
        $this->line('Run it from any machine that can reach the database:');
        $this->line('  psql "postgres://avnadmin:PASSWORD@HOST:PORT/defaultdb?sslmode=require" \\');
        $this->line('      -f '.basename($absolute));

        return self::SUCCESS;
    }

    /** Resolves a relative path against the app root, leaving absolute ones alone. */
    private function absolutePath(string $path): string
    {
        $absolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            // Windows drive letter: C:\... or C:/...
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':');

        return $absolute ? $path : base_path($path);
    }

    private function sqlHeader(array $order, array $counts): string
    {
        $tables = count($order);
        $rows = number_format(array_sum($counts));
        $schema = $this->schema;

        return <<<SQL
        --
        -- Airking Inventory — business data for PostgreSQL.
        --
        -- GENERATED FILE — do not edit by hand.
        -- Regenerate with:  php artisan data:transfer --sql=<file>
        -- Source: MySQL database "{$schema}" — {$tables} tables, {$rows} rows.
        --
        -- CONTAINS REAL DATA, including password hashes and customer records.
        -- Do not commit this file. Delete it once it has been loaded.
        --
        -- Load it with:
        --   psql "postgres://avnadmin:PASSWORD@HOST:PORT/defaultdb?sslmode=require" -f <file>
        --
        -- Assumes the target already has the schema (php artisan migrate). It
        -- EMPTIES every table listed below before reloading it, so anything in
        -- the target that is not also in the source is discarded.
        --
        -- Everything runs in one transaction and ends with assertions that RAISE
        -- on a mismatch, so a partial load rolls back rather than reporting
        -- success.
        --

        \set ON_ERROR_STOP on

        SET client_min_messages TO warning;
        SET standard_conforming_strings TO on;

        BEGIN;


        SQL;
    }

    /** Streams one table's rows out as chunked multi-row INSERTs. */
    private function writeInserts(callable $write, string $table, $bar): void
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $deferred = array_flip($this->deferredColumns[$table] ?? []);
        $key = $this->primaryKeys[$table] ?? null;

        $query = $this->source->table($table);
        $key ? $query->orderBy($key) : $query->orderByRaw('1');

        $query->chunk($chunk, function ($rows) use ($write, $table, $deferred, $bar) {
            $columns = array_keys((array) $rows->first());

            $values = $rows->map(function ($row) use ($table, $columns, $deferred) {
                $rendered = array_map(
                    fn ($column) => isset($deferred[$column])
                        ? 'NULL'
                        : $this->sqlValue($table, $column, ((array) $row)[$column]),
                    $columns
                );

                return '    ('.implode(', ', $rendered).')';
            })->implode(",\n");

            $write('INSERT INTO '.$this->q($table).' ('
                .implode(', ', array_map(fn ($c) => $this->q($c), $columns))
                .") VALUES\n".$values.";\n");

            $bar->advance($rows->count());
        });
    }

    /**
     * Renders one value as a PostgreSQL literal.
     *
     * Everything except booleans is quoted, including numbers and timestamps:
     * an unadorned quoted literal has type "unknown" and PostgreSQL casts it to
     * whatever the column is. That is one rule instead of a per-type table, and
     * one rule cannot disagree with itself.
     */
    private function sqlValue(string $table, string $column, mixed $value): string
    {
        $converted = $this->convert($table, $column, $value);

        if ($converted === null) {
            return 'NULL';
        }

        if (is_bool($converted)) {
            return $converted ? 'true' : 'false';
        }

        return $this->literal((string) $converted);
    }

    private function sqlDeferredUpdates(): string
    {
        if ($this->deferredColumns === []) {
            return '';
        }

        $sql = "--\n-- Self-referencing columns, held back until every row exists.\n--\n";

        foreach ($this->deferredColumns as $table => $columns) {
            $key = $this->primaryKeys[$table];

            foreach ($columns as $column) {
                foreach ($this->source->table($table)->whereNotNull($column)->pluck($column, $key) as $id => $value) {
                    $sql .= 'UPDATE '.$this->q($table).' SET '.$this->q($column).' = '
                        .$this->sqlValue($table, $column, $value)
                        .' WHERE '.$this->q($key).' = '.$this->literal((string) $id).";\n";
                }
            }
        }

        return $sql."\n";
    }

    private function sqlSequences(array $order): string
    {
        $sql = "--\n-- Advance each identity sequence past the rows just inserted, or the\n"
            ."-- next insert the application makes collides on the primary key.\n--\n";

        foreach ($order as $table) {
            if (! $column = $this->primaryKeys[$table] ?? null) {
                continue;
            }

            // Resolved at run time rather than here, so this does not depend on
            // the target's sequence naming. is_called is false on an empty
            // table, so the next value is 1 rather than 2.
            $sql .= "SELECT setval(pg_get_serial_sequence('{$table}', '{$column}'),\n"
                .'    COALESCE((SELECT MAX('.$this->q($column).') FROM '.$this->q($table)."), 1),\n"
                .'    (SELECT MAX('.$this->q($column).') FROM '.$this->q($table).") IS NOT NULL)\n"
                ."WHERE pg_get_serial_sequence('{$table}', '{$column}') IS NOT NULL;\n";
        }

        return $sql."\n";
    }

    /**
     * The same two assertions the live path makes, as SQL that aborts on failure.
     *
     * RAISE EXCEPTION inside the transaction means a mismatch rolls the whole
     * load back. Without this the script would happily COMMIT a table that
     * silently loaded zero rows.
     */
    private function sqlAssertions(array $order, array $counts): string
    {
        $sql = "--\n-- Verification. A mismatch raises, which rolls back everything above.\n--\n";

        $sql .= "DO \$\$\nDECLARE n bigint;\nBEGIN\n";

        foreach ($order as $table) {
            $sql .= '    SELECT count(*) INTO n FROM '.$this->q($table).";\n"
                ."    IF n <> {$counts[$table]} THEN\n"
                ."        RAISE EXCEPTION '{$table}: expected {$counts[$table]} rows, found %', n;\n"
                ."    END IF;\n";
        }

        $sql .= "END\n\$\$;\n\n";

        $pairs = [];

        foreach ($order as $table) {
            if ($counts[$table] > 0 && $column = $this->primaryKeys[$table] ?? null) {
                $pairs[] = '        ('.$this->literal($table).', '.$this->literal($column).')';
            }
        }

        if ($pairs !== []) {
            $sql .= "DO \$\$\nDECLARE\n    r record;\n    seq text;\n    lastval bigint;\n    maxid bigint;\nBEGIN\n"
                ."    FOR r IN SELECT * FROM (VALUES\n".implode(",\n", $pairs)."\n    ) AS t(tbl, col) LOOP\n"
                ."        seq := pg_get_serial_sequence(r.tbl, r.col);\n"
                ."        CONTINUE WHEN seq IS NULL;\n"
                ."        EXECUTE format('SELECT last_value FROM %s', seq) INTO lastval;\n"
                ."        EXECUTE format('SELECT COALESCE(MAX(%I), 0) FROM %I', r.col, r.tbl) INTO maxid;\n"
                ."        IF lastval < maxid THEN\n"
                ."            RAISE EXCEPTION 'sequence for %.% is at %, behind max id %', r.tbl, r.col, lastval, maxid;\n"
                ."        END IF;\n"
                ."    END LOOP;\n"
                ."END\n\$\$;\n\n";
        }

        return $sql;
    }

    // ------------------------------------------------------------------
    // Quoting
    //
    // Only --sql needs these. The live path binds values as parameters, which
    // is both safer and faster; a generated script has no parameters to bind to.
    // ------------------------------------------------------------------

    /** Quote an identifier for PostgreSQL. */
    private function q(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * Quote a string literal for PostgreSQL.
     *
     * Doubling the quote is the whole escape, and it is correct only because
     * the header sets standard_conforming_strings — without it a backslash in
     * the data would be read as an escape character.
     */
    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    // ------------------------------------------------------------------
    // Verification
    // ------------------------------------------------------------------

    /**
     * Three assertions, run before the commit. Any failure rolls everything back.
     *
     * @return list<string> human-readable failures, empty when all three hold
     */
    private function verify(array $order, array $counts): array
    {
        $this->line('Verifying…');

        $failures = [];

        // 1. Row-count parity. A table that silently moved zero rows is the
        //    failure this exists to catch.
        foreach ($order as $table) {
            $actual = $this->target->table($table)->count();

            if ($actual !== $counts[$table]) {
                $failures[] = "{$table}: expected {$counts[$table]} rows, found {$actual}";
            }
        }

        // 2. Sequence health — the check for resyncSequences().
        foreach ($order as $table) {
            if ($counts[$table] === 0 || ! $sequence = $this->sequenceFor($table)) {
                continue;
            }

            $column = $this->primaryKeys[$table];
            $max = (int) $this->target->table($table)->max($column);
            $last = (int) $this->target->selectOne(
                'SELECT last_value FROM '.$sequence
            )->last_value;

            if ($last < $max) {
                $failures[] = "{$table}: sequence at {$last} but max({$column}) is {$max}";
            }
        }

        // 3. Referential integrity. PostgreSQL enforces this on insert, so a
        //    violation here means a constraint is missing from the baseline
        //    rather than that the data is bad — worth knowing either way.
        foreach ($this->foreignKeys($order) as $table => $parents) {
            foreach ($parents as $parent => $columns) {
                foreach ($columns as $column) {
                    $parentKey = $this->parentKeyFor($table, $column, $parent);

                    if ($parentKey === null) {
                        continue;
                    }

                    $orphans = $this->target->table($table.' as c')
                        ->leftJoin($parent.' as p', 'c.'.$column, '=', 'p.'.$parentKey)
                        ->whereNotNull('c.'.$column)
                        ->whereNull('p.'.$parentKey)
                        ->count();

                    if ($orphans > 0) {
                        $failures[] = "{$table}.{$column}: {$orphans} row(s) point at a missing {$parent}";
                    }
                }
            }
        }

        return $failures;
    }

    private function parentKeyFor(string $table, string $column, string $parent): ?string
    {
        $row = $this->source->selectOne(
            'SELECT REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME = ?',
            [$this->schema, $table, $column, $parent]
        );

        return $row->REFERENCED_COLUMN_NAME ?? null;
    }
}
