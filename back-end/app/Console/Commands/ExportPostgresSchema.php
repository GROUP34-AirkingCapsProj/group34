<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates database/schema/pgsql-schema.sql from the live MySQL/MariaDB schema.
 *
 * Why generate rather than hand-convert the phpMyAdmin dump: airkingv5.sql is a
 * snapshot at 76 tables, while the database has since grown to 86 through the
 * migration chain. Reading information_schema means the baseline always reflects
 * what the application actually runs against, and re-running this command after
 * new migrations keeps it that way.
 *
 * The output is consumed by Laravel's squashed-schema mechanism. On a fresh
 * database `php artisan migrate`:
 *   1. creates the migrations table, finds it empty,
 *   2. drops it again (MigrateCommand::loadSchemaState),
 *   3. pipes this file through psql,
 *   4. runs any migration not listed in the migrations table this file restores.
 * So the file must both create every table AND repopulate the migrations table —
 * that last part is what marks the historical migrations as already resolved.
 *
 *   php artisan schema:export-pgsql
 *
 * Requires only a MySQL connection; it never touches PostgreSQL.
 */
class ExportPostgresSchema extends Command
{
    protected $signature = 'schema:export-pgsql
        {--connection= : Source MySQL/MariaDB connection (defaults to the configured default)}
        {--output=database/schema/pgsql-schema.sql : Destination, relative to the app base path}
        {--with-reference-data : Also emit rows for the configuration tables the app cannot boot without}';

    protected $description = 'Generate the PostgreSQL baseline schema from the live MySQL schema';

    /**
     * Configuration tables, in insertion order (parents before children).
     *
     * These hold what the application treats as fixed configuration rather than
     * business records: without roles, permissions and status_lookup, login and
     * every authorisation check fail on a freshly created database — the app
     * does not create them at boot, and TestBaselineSeeder explicitly refuses to
     * run when they are missing.
     *
     * Deliberately excluded: users (password hashes), personal_access_tokens and
     * the password_reset_* tables (live credentials), cache, and every
     * transactional table (products, inventory, audit_trail, ...). Moving those
     * is a data-migration decision, not part of a schema baseline.
     */
    public const REFERENCE_TABLES = [
        'status_lookup',
        'roles',
        'permissions',
        'role_permissions',
        'nav_permission_bundles',
        'nav_bundle_permissions',
        'transaction_type_lookup',
        'locations',
        'category_lookup',
        'brand_lookup',
        'unit_lookup',
        'ac_type_lookup',
        'capacity_lookup',
        'variant_lookup',
        'system_settings',
    ];

    /**
     * Columns holding JSON, declared as `longtext` in MySQL.
     *
     * These are the columns the models cast to 'array'. MySQL is happy to run
     * Laravel's JSON path queries against a text column, but PostgreSQL is not:
     * `new_values->'account'` there is the jsonb `->` operator, and against a
     * text column it fails with "operator does not exist: text -> unknown".
     * Emitting them as jsonb makes whereJsonContains() and `->` work natively,
     * and gets the schema closer to what the data actually is.
     *
     * Keep in sync with the models' $casts. If a column here ever holds text
     * that is not valid JSON, loading it into jsonb will fail loudly — which is
     * the right outcome, since Laravel would have failed to decode it anyway.
     */
    public const JSON_COLUMNS = [
        'archive_logs.modules_processed',
        'audit_trail.old_values',
        'audit_trail.new_values',
        'inventory_scan_events.meta',
        'pending_products.appliance_snapshot',
        'report_runs.parameters',
        'restock_recommendations.reason_codes',
        'stock_parameter_suggestions.reason_codes',
    ];

    /**
     * Reference rows that must NOT travel, as table => [column => [values]].
     *
     * Not everything in a configuration table is configuration. These rows hold
     * state belonging to the machine that produced the dump:
     *
     *   - label_printers maps each location to a printer, and on a developer box
     *     that means Windows temp paths and 127.0.0.1 ports left over from local
     *     testing. Shipping them would give a fresh production database a set of
     *     printer mappings pointing at a laptop. Printers are configured in-app
     *     per location (System Configuration), so the right starting state is
     *     absent, not wrong.
     *   - archive_last_run is a timestamped record of the last archive sweep.
     *     A new database has never run one.
     *   - backup_schedule and backup_last_run carry the same kind of
     *     machine-specific run state (last_scheduled_run_date, a local
     *     storage_path override, which log row was last successful) — a
     *     fresh database has never run a backup either.
     */
    public const REFERENCE_ROW_EXCLUSIONS = [
        'system_settings' => [
            'key' => ['label_printers', 'archive_last_run', 'backup_schedule', 'backup_last_run'],
        ],
    ];

    /** Columns whose MySQL type maps to a PostgreSQL boolean, as "table.column". */
    private array $booleanColumns = [];

    /** Warnings surfaced at the end rather than silently swallowed. */
    private array $notes = [];

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error("Source connection [{$connection}] is [{$driver}]; this command reads a MySQL/MariaDB schema.");

            return self::FAILURE;
        }

        $schema = $db->getDatabaseName();
        $this->info("Reading schema from {$driver} database [{$schema}]…");

        $tables = $this->tableNames($db, $schema);
        $this->line('  '.count($tables).' base tables');

        $sql = $this->buildScript($db, $schema, $tables);

        $path = base_path($this->option('output'));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // LF endings: this file is executed by psql inside a Linux container.
        file_put_contents($path, str_replace("\r\n", "\n", $sql));

        $this->newLine();
        $this->info('Wrote '.$this->option('output'));
        $this->line('  tables:      '.count($tables));
        $this->line('  size:        '.number_format(strlen($sql) / 1024, 1).' KiB');

        foreach ($this->notes as $note) {
            $this->warn('  note: '.$note);
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function tableNames($db, string $schema): array
    {
        return array_map(
            fn ($r) => $r->TABLE_NAME,
            $db->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
                [$schema, 'BASE TABLE']
            )
        );
    }

    private function buildScript($db, string $schema, array $tables): string
    {
        $out = [];

        $out[] = $this->header($schema, count($tables));

        // One transaction for the whole file: PostgreSQL DDL is transactional, so
        // a failure anywhere leaves the database untouched rather than half-built.
        $out[] = "BEGIN;\n";

        // Tables first, then every constraint that points between them. Splitting
        // it this way means table order never matters, so no topological sort and
        // no circular-dependency trap.
        foreach ($tables as $table) {
            $out[] = $this->createTable($db, $schema, $table);
        }

        $out[] = "--\n-- Indexes\n--\n";
        foreach ($tables as $table) {
            $out[] = $this->indexes($db, $schema, $table);
        }

        $out[] = "--\n-- Foreign keys\n--\n";
        foreach ($tables as $table) {
            $out[] = $this->foreignKeys($db, $schema, $table);
        }

        if ($this->option('with-reference-data')) {
            $out[] = $this->referenceData($db, $schema);
        }

        $out[] = $this->migrationRows($db);

        $out[] = "COMMIT;\n";

        return implode('', array_filter($out));
    }

    private function header(string $schema, int $tableCount): string
    {
        return <<<SQL
        --
        -- PostgreSQL baseline schema for Airking Inventory.
        --
        -- GENERATED FILE — do not edit by hand.
        -- Regenerate with:  php artisan schema:export-pgsql
        -- Source: MySQL/MariaDB schema "{$schema}" ({$tableCount} base tables).
        --
        -- Loaded automatically by "php artisan migrate" on a database with no
        -- applied migrations (Laravel's squashed-schema mechanism). It restores
        -- the migrations table at the end, which is what tells Laravel the
        -- historical migrations are already applied.
        --
        -- Note: loading requires the psql client binary on PATH — Laravel shells
        -- out to it (PostgresSchemaState::load). The production image installs it
        -- via the postgresql-client package.
        --
        -- Deliberately has no DROP statements: this is additive, and running it
        -- against a populated database fails loudly instead of destroying it.
        --

        SET client_min_messages TO warning;
        SET standard_conforming_strings TO on;


        SQL;
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    private function createTable($db, string $schema, string $table): string
    {
        $columns = $db->select(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                    EXTRA, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                    COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$schema, $table]
        );

        $lines = [];
        $checks = [];

        foreach ($columns as $col) {
            [$definition, $check] = $this->columnDefinition($table, $col);
            $lines[] = '    '.$definition;

            if ($check !== null) {
                $checks[] = '    '.$check;
            }
        }

        foreach ($this->primaryKey($db, $schema, $table) as $pk) {
            $lines[] = '    '.$pk;
        }

        $lines = array_merge($lines, $checks);

        $sql = "--\n-- Table: {$table}\n--\n";
        $sql .= 'CREATE TABLE '.$this->q($table)." (\n";
        $sql .= implode(",\n", $lines);
        $sql .= "\n);\n";

        // Column comments carry meaning in this schema (see the unit_lookup
        // migration), so they are preserved rather than dropped.
        foreach ($columns as $col) {
            if ($col->COLUMN_COMMENT !== null && $col->COLUMN_COMMENT !== '') {
                $sql .= 'COMMENT ON COLUMN '.$this->q($table).'.'.$this->q($col->COLUMN_NAME)
                    .' IS '.$this->literal($col->COLUMN_COMMENT).";\n";
            }
        }

        return $sql."\n";
    }

    /**
     * @return array{0:string, 1:?string}  [column definition, optional CHECK]
     */
    private function columnDefinition(string $table, object $col): array
    {
        $name = $col->COLUMN_NAME;
        $type = $this->pgType($table, $col);
        $check = null;

        $definition = $this->q($name).' '.$type;

        // MySQL ENUM has no PostgreSQL equivalent that Laravel's schema builder
        // would produce. Laravel's own PostgresGrammar renders enum() as a
        // varchar with a CHECK, so match that exactly — a native CREATE TYPE
        // would drift from what any future Laravel migration emits.
        if (strtolower($col->DATA_TYPE) === 'enum') {
            $allowed = $this->enumValues($col->COLUMN_TYPE);
            $check = 'CONSTRAINT '.$this->q($table.'_'.$name.'_check')
                .' CHECK ('.$this->q($name).' IN ('.implode(', ', array_map(
                    fn ($v) => $this->literal($v),
                    $allowed
                )).'))';
        }

        if (str_contains(strtolower($col->EXTRA), 'auto_increment')) {
            // BY DEFAULT (not ALWAYS): explicit ids must still be insertable, or
            // a data import and Eloquent's occasional explicit-id write both fail.
            $definition .= ' GENERATED BY DEFAULT AS IDENTITY';
        }

        $definition .= $col->IS_NULLABLE === 'NO' ? ' NOT NULL' : '';

        $default = $this->defaultExpression($table, $col);

        if ($default !== null) {
            $definition .= ' DEFAULT '.$default;
        }

        return [$definition, $check];
    }

    private function pgType(string $table, object $col): string
    {
        $columnType = strtolower($col->COLUMN_TYPE);
        $dataType = strtolower($col->DATA_TYPE);
        $unsigned = str_contains($columnType, 'unsigned');

        // Declared as text in MySQL but actually JSON — see JSON_COLUMNS.
        if (in_array($table.'.'.$col->COLUMN_NAME, self::JSON_COLUMNS, true)) {
            return 'jsonb';
        }

        // Laravel's boolean() creates tinyint(1); anything wider is a real number.
        if ($columnType === 'tinyint(1)') {
            $this->booleanColumns[$table.'.'.$col->COLUMN_NAME] = true;

            return 'boolean';
        }

        $length = $col->CHARACTER_MAXIMUM_LENGTH;

        return match ($dataType) {
            // PostgreSQL has no unsigned types. Widening keeps the full MySQL
            // range representable: int unsigned reaches 4294967295, which would
            // overflow a PostgreSQL integer (max 2147483647).
            'tinyint' => 'smallint',
            'smallint' => $unsigned ? 'integer' : 'smallint',
            'mediumint' => 'integer',
            'int', 'integer' => $unsigned ? 'bigint' : 'integer',
            'bigint' => 'bigint',

            'decimal', 'numeric' => "numeric({$col->NUMERIC_PRECISION},{$col->NUMERIC_SCALE})",
            'float' => 'real',
            'double' => 'double precision',

            'char' => "char({$length})",
            'varchar' => "varchar({$length})",
            // PostgreSQL text is unbounded, so all four MySQL sizes collapse onto it.
            'tinytext', 'text', 'mediumtext', 'longtext' => 'text',

            'date' => 'date',
            // Matches what Laravel's PostgresGrammar emits for timestamp()/dateTime().
            'datetime', 'timestamp' => 'timestamp(0) without time zone',
            'time' => 'time(0) without time zone',
            'year' => 'smallint',

            'json' => 'jsonb',
            'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' => 'bytea',

            'enum' => 'varchar(255)',

            default => throw new RuntimeException(
                "No PostgreSQL mapping for MySQL type [{$columnType}] "
                ."on {$table}.{$col->COLUMN_NAME}. Add one to ExportPostgresSchema::pgType()."
            ),
        };
    }

    private function defaultExpression(string $table, object $col): ?string
    {
        $raw = $col->COLUMN_DEFAULT;

        if ($raw === null) {
            return null;
        }

        // MariaDB 10.4 reports a NULL default as the four-character string "NULL",
        // and reports string defaults already wrapped in single quotes. Both differ
        // from MySQL 8, which is why this cannot just pass the value through.
        if ($raw === 'NULL') {
            return null;
        }

        $lower = strtolower(trim($raw));

        if (in_array($lower, ['current_timestamp', 'current_timestamp()', 'now()'], true)) {
            return 'CURRENT_TIMESTAMP';
        }

        $isBoolean = isset($this->booleanColumns[$table.'.'.$col->COLUMN_NAME]);

        if ($isBoolean) {
            return trim($raw, "'") === '1' ? 'true' : 'false';
        }

        // Already quoted by MariaDB: unwrap, then re-quote with PostgreSQL rules
        // so an embedded quote cannot break out of the literal.
        if (strlen($raw) >= 2 && str_starts_with($raw, "'") && str_ends_with($raw, "'")) {
            return $this->literal(str_replace("''", "'", substr($raw, 1, -1)));
        }

        if (is_numeric($raw)) {
            return $raw;
        }

        // An unquoted, non-numeric, non-keyword default is unexpected; quote it
        // rather than emitting it as a bare SQL expression.
        $this->notes[] = "unusual default on {$table}.{$col->COLUMN_NAME}: {$raw} (quoted as a literal)";

        return $this->literal($raw);
    }

    /** @return list<string> */
    private function enumValues(string $columnType): array
    {
        if (! preg_match('/^enum\((.*)\)$/i', $columnType, $m)) {
            throw new RuntimeException("Cannot parse enum definition [{$columnType}].");
        }

        preg_match_all("/'((?:[^']|'')*)'/", $m[1], $values);

        return array_map(fn ($v) => str_replace("''", "'", $v), $values[1]);
    }

    /** @return list<string> */
    private function primaryKey($db, string $schema, string $table): array
    {
        $columns = $db->select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX',
            [$schema, $table, 'PRIMARY']
        );

        if ($columns === []) {
            $this->notes[] = "table {$table} has no primary key";

            return [];
        }

        $names = array_map(fn ($r) => $this->q($r->COLUMN_NAME), $columns);

        return ['CONSTRAINT '.$this->q($table.'_pkey').' PRIMARY KEY ('.implode(', ', $names).')'];
    }

    // ------------------------------------------------------------------
    // Indexes and foreign keys
    // ------------------------------------------------------------------

    private function indexes($db, string $schema, string $table): string
    {
        $rows = $db->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME <> ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$schema, $table, 'PRIMARY']
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->INDEX_NAME]['unique'] = ! (int) $row->NON_UNIQUE;
            $grouped[$row->INDEX_NAME]['columns'][] = $row->COLUMN_NAME;
        }

        $sql = '';

        foreach ($grouped as $name => $index) {
            $columns = implode(', ', array_map(fn ($c) => $this->q($c), $index['columns']));

            // MySQL index names are scoped per table; PostgreSQL index names must
            // be unique across the whole schema. This schema happens to have no
            // collisions today, so names are preserved as-is — but a future
            // duplicate would fail loudly here rather than silently rename.
            if ($index['unique']) {
                // Emitted as a constraint, not a plain unique index, so that
                // Schema::getIndexes() and Laravel's dropUnique() both see it the
                // same way they do on MySQL.
                $sql .= 'ALTER TABLE '.$this->q($table).' ADD CONSTRAINT '.$this->q($name)
                    ." UNIQUE ({$columns});\n";
            } else {
                $sql .= 'CREATE INDEX '.$this->q($name).' ON '.$this->q($table)." ({$columns});\n";
            }
        }

        return $sql;
    }

    private function foreignKeys($db, string $schema, string $table): string
    {
        $rows = $db->select(
            'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE, k.ORDINAL_POSITION
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ?
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$schema, $table]
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->CONSTRAINT_NAME]['local'][] = $row->COLUMN_NAME;
            $grouped[$row->CONSTRAINT_NAME]['foreign'][] = $row->REFERENCED_COLUMN_NAME;
            $grouped[$row->CONSTRAINT_NAME]['table'] = $row->REFERENCED_TABLE_NAME;
            $grouped[$row->CONSTRAINT_NAME]['delete'] = $row->DELETE_RULE;
            $grouped[$row->CONSTRAINT_NAME]['update'] = $row->UPDATE_RULE;
        }

        $sql = '';

        foreach ($grouped as $name => $fk) {
            $local = implode(', ', array_map(fn ($c) => $this->q($c), $fk['local']));
            $foreign = implode(', ', array_map(fn ($c) => $this->q($c), $fk['foreign']));

            $sql .= 'ALTER TABLE '.$this->q($table).' ADD CONSTRAINT '.$this->q($name)
                ." FOREIGN KEY ({$local}) REFERENCES ".$this->q($fk['table'])." ({$foreign})";

            if ($fk['delete'] !== 'RESTRICT' && $fk['delete'] !== 'NO ACTION') {
                $sql .= ' ON DELETE '.$fk['delete'];
            }

            // MySQL reports plain NO ACTION as RESTRICT. PostgreSQL's default is
            // NO ACTION, so emitting nothing here reproduces MySQL's behaviour.
            if ($fk['update'] !== 'RESTRICT' && $fk['update'] !== 'NO ACTION') {
                $sql .= ' ON UPDATE '.$fk['update'];
            }

            $sql .= ";\n";
        }

        return $sql;
    }

    // ------------------------------------------------------------------
    // Reference data
    // ------------------------------------------------------------------

    private function referenceData($db, string $schema): string
    {
        $sql = "--\n-- Reference data: configuration the application cannot boot without.\n"
            ."-- Business records (products, inventory, audit history) and credentials\n"
            ."-- are deliberately not included — migrating those is a separate decision.\n--\n\n";

        $identityColumns = [];

        foreach (self::REFERENCE_TABLES as $table) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                $this->notes[] = "reference table {$table} does not exist; skipped";

                continue;
            }

            $rows = $db->table($table)->get();

            foreach (self::REFERENCE_ROW_EXCLUSIONS[$table] ?? [] as $column => $values) {
                $before = $rows->count();
                $rows = $rows->reject(fn ($row) => in_array(((array) $row)[$column] ?? null, $values, true))->values();

                if ($dropped = $before - $rows->count()) {
                    $this->notes[] = "{$table}: excluded {$dropped} machine-specific row(s) by {$column}";
                }
            }

            if ($rows->isEmpty()) {
                $this->notes[] = "reference table {$table} is empty";

                continue;
            }

            $columns = array_keys((array) $rows->first());
            $booleans = $this->booleanColumnsOf($table, $columns);

            $sql .= "-- {$table}: {$rows->count()} rows\n";
            $sql .= 'INSERT INTO '.$this->q($table).' ('
                .implode(', ', array_map(fn ($c) => $this->q($c), $columns)).") VALUES\n";

            // One multi-row statement per table. Foreign keys are checked at the
            // end of the statement, so a self-referencing table (locations has
            // parent_location_id) loads without ordering rows by hand.
            $sql .= collect($rows)->map(function ($row) use ($columns, $booleans) {
                $values = array_map(
                    fn ($c) => $this->value(((array) $row)[$c], in_array($c, $booleans, true)),
                    $columns
                );

                return '    ('.implode(', ', $values).')';
            })->implode(",\n").";\n\n";

            if ($identity = $this->identityColumnOf($db, $schema, $table)) {
                $identityColumns[$table] = $identity;
            }

            $this->line("  reference rows: {$table} ({$rows->count()})");
        }

        if ($identityColumns !== []) {
            $sql .= "-- Advance each identity sequence past the rows just inserted, or the\n"
                ."-- next application insert collides on the primary key.\n";

            foreach ($identityColumns as $table => $column) {
                $sql .= "SELECT setval(pg_get_serial_sequence('{$table}', '{$column}'), "
                    .'(SELECT COALESCE(MAX('.$this->q($column).'), 1) FROM '.$this->q($table)."));\n";
            }

            $sql .= "\n";
        }

        return $sql;
    }

    /** @return list<string> */
    private function booleanColumnsOf(string $table, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn ($c) => isset($this->booleanColumns[$table.'.'.$c])
        ));
    }

    private function identityColumnOf($db, string $schema, string $table): ?string
    {
        $row = $db->selectOne(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA LIKE ?',
            [$schema, $table, '%auto_increment%']
        );

        return $row->COLUMN_NAME ?? null;
    }

    /** Render a PHP value as a PostgreSQL literal. */
    private function value(mixed $value, bool $isBoolean): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($isBoolean) {
            return ((int) $value) === 1 ? 'true' : 'false';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->literal((string) $value);
    }

    // ------------------------------------------------------------------
    // Migration history
    // ------------------------------------------------------------------

    /**
     * Repopulates the migrations table.
     *
     * This is the step that marks the historical migrations as resolved: without
     * it, `migrate` would follow the schema load by trying to run all 100+
     * migrations against tables that already exist.
     */
    private function migrationRows($db): string
    {
        $rows = $db->table('migrations')->orderBy('id')->get();

        $sql = "--\n-- Migration history: every migration already represented by the\n"
            ."-- schema above. Restoring these rows is what stops migrate from\n"
            ."-- re-running them against tables that already exist.\n--\n";

        if ($rows->isEmpty()) {
            $this->notes[] = 'the source migrations table is empty; migrate would re-run every migration';

            return $sql."-- (source migrations table was empty)\n\n";
        }

        $values = $rows->map(fn ($r) => '    ('
            .$this->literal($r->migration).', '.(int) $r->batch
            .')')->implode(",\n");

        $sql .= 'INSERT INTO '.$this->q('migrations').' ('.$this->q('migration').', '.$this->q('batch').") VALUES\n";
        $sql .= $values.";\n\n";

        // The identity sequence must be moved past the restored rows, or the next
        // migration to record itself collides on the primary key.
        $sql .= "SELECT setval(pg_get_serial_sequence('migrations', 'id'), "
            .'(SELECT MAX(id) FROM '.$this->q('migrations')."));\n\n";

        $this->line('  migration rows: '.$rows->count());

        return $sql;
    }

    // ------------------------------------------------------------------
    // Quoting
    // ------------------------------------------------------------------

    /** Quote an identifier for PostgreSQL. */
    private function q(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** Quote a string literal for PostgreSQL. */
    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
