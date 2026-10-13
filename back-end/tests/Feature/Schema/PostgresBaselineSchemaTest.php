<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards database/schema/pgsql-schema.sql — the PostgreSQL baseline that
 * `php artisan migrate` loads on a fresh Aiven database.
 *
 * Two kinds of check live here:
 *
 *   - Self-consistency, parsed straight out of the file. These need no database
 *     at all, so they run in CI and on any machine, and they catch the failure
 *     modes that only show up once psql is already running the file: a foreign
 *     key pointing at a table that was never created, two indexes sharing a name
 *     (legal in MySQL, fatal in PostgreSQL), leftover MySQL syntax.
 *
 *   - Parity with the live MySQL schema, skipped unless the test connection is
 *     MySQL. This is what catches a stale file after someone adds a migration
 *     and forgets to re-run `php artisan schema:export-pgsql`.
 */
class PostgresBaselineSchemaTest extends TestCase
{
    // 87 -> 89 on 2026-09-30: the audit evidence trail added inventory_ledger and
    // ledger_drift_findings.
    /**
     * Was 89 until `drop_budget_management` removed budget_allocations and its
     * sibling. A migration that adds or drops a table has to move this number
     * too, or the only signal is this assertion failing with no explanation of
     * which table changed.
     */
    private const EXPECTED_TABLES = 87;

    private string $sql;

    protected function setUp(): void
    {
        parent::setUp();

        $path = database_path('schema/pgsql-schema.sql');

        $this->assertFileExists($path, 'Run: php artisan schema:export-pgsql');

        $this->sql = file_get_contents($path);
    }

    // ------------------------------------------------------------------
    // Parsing helpers
    // ------------------------------------------------------------------

    /** @return array<string, array<string,string>>  table => [column => type] */
    private function tables(): array
    {
        preg_match_all('/CREATE TABLE "([a-z0-9_]+)" \((.*?)\n\);/s', $this->sql, $matches, PREG_SET_ORDER);

        $tables = [];

        foreach ($matches as [$_, $table, $body]) {
            $columns = [];

            foreach (explode("\n", $body) as $line) {
                if (! preg_match('/^\s+"([a-z0-9_]+)" (.+)$/', $line, $column)) {
                    continue; // CONSTRAINT lines, not columns.
                }

                $type = $column[2];

                foreach ([' GENERATED', ' NOT NULL', ' DEFAULT'] as $stop) {
                    if (($at = strpos($type, $stop)) !== false) {
                        $type = substr($type, 0, $at);
                    }
                }

                $columns[$column[1]] = rtrim(trim($type), ',');
            }

            $tables[$table] = $columns;
        }

        return $tables;
    }

    /** @return list<array{table:string, column:string, refTable:string, refColumn:string}> */
    private function foreignKeys(): array
    {
        preg_match_all(
            '/ALTER TABLE "([a-z0-9_]+)" ADD CONSTRAINT "([^"]+)" FOREIGN KEY \("([a-z0-9_]+)"\) '
            .'REFERENCES "([a-z0-9_]+)" \("([a-z0-9_]+)"\)/',
            $this->sql,
            $matches,
            PREG_SET_ORDER
        );

        return array_map(fn ($m) => [
            'table' => $m[1],
            'column' => $m[3],
            'refTable' => $m[4],
            'refColumn' => $m[5],
        ], $matches);
    }

    // ------------------------------------------------------------------
    // Self-consistency
    // ------------------------------------------------------------------

    public function test_it_creates_every_table(): void
    {
        $this->assertCount(
            self::EXPECTED_TABLES,
            $this->tables(),
            'Baseline table count changed. If a migration added a table, re-run '
            .'php artisan schema:export-pgsql and update EXPECTED_TABLES.'
        );
    }

    public function test_it_contains_no_mysql_syntax(): void
    {
        // Each of these is either a hard syntax error in PostgreSQL or silently
        // ignored, and all of them appear in the phpMyAdmin dump this replaces.
        foreach (['`', 'ENGINE=', 'utf8mb4', 'AUTO_INCREMENT', 'unsigned', 'tinyint',
            'longtext', 'mediumtext', 'datetime', 'ON UPDATE CURRENT_TIMESTAMP',
        ] as $token) {
            $this->assertStringNotContainsString(
                $token,
                $this->sql,
                "MySQL-specific token [{$token}] leaked into the PostgreSQL baseline."
            );
        }
    }

    public function test_every_foreign_key_points_at_a_column_that_exists(): void
    {
        $tables = $this->tables();
        $foreignKeys = $this->foreignKeys();

        $this->assertNotEmpty($foreignKeys);

        foreach ($foreignKeys as $fk) {
            $this->assertArrayHasKey(
                $fk['refTable'],
                $tables,
                "FK {$fk['table']}.{$fk['column']} references missing table {$fk['refTable']}."
            );

            $this->assertArrayHasKey(
                $fk['refColumn'],
                $tables[$fk['refTable']],
                "FK {$fk['table']}.{$fk['column']} references missing column "
                ."{$fk['refTable']}.{$fk['refColumn']}."
            );
        }
    }

    public function test_foreign_key_column_types_match_what_they_reference(): void
    {
        $tables = $this->tables();

        foreach ($this->foreignKeys() as $fk) {
            $local = $tables[$fk['table']][$fk['column']] ?? null;
            $referenced = $tables[$fk['refTable']][$fk['refColumn']] ?? null;

            $this->assertSame(
                $referenced,
                $local,
                "FK {$fk['table']}.{$fk['column']} is {$local} but references "
                ."{$fk['refTable']}.{$fk['refColumn']} which is {$referenced}."
            );
        }
    }

    public function test_index_and_constraint_names_are_unique_across_the_schema(): void
    {
        // MySQL scopes index names per table; PostgreSQL requires them to be
        // unique across the whole schema. A duplicate is fine in the source
        // database and fatal here, so it has to be caught before deploy.
        $names = [];

        preg_match_all('/CREATE INDEX "([^"]+)"/', $this->sql, $m);
        $names = array_merge($names, $m[1]);

        preg_match_all('/ADD CONSTRAINT "([^"]+)" UNIQUE/', $this->sql, $m);
        $names = array_merge($names, $m[1]);

        preg_match_all('/CONSTRAINT "([^"]+)" PRIMARY KEY/', $this->sql, $m);
        $names = array_merge($names, $m[1]);

        $duplicates = array_keys(array_filter(array_count_values($names), fn ($n) => $n > 1));

        $this->assertSame([], $duplicates, 'Duplicate index/constraint names: '.implode(', ', $duplicates));
    }

    public function test_it_restores_the_migration_history(): void
    {
        // Without these rows, migrate would follow the schema load by trying to
        // run every historical migration against tables that already exist.
        $this->assertMatchesRegularExpression(
            '/INSERT INTO "migrations" \("migration", "batch"\) VALUES/',
            $this->sql
        );

        preg_match('/INSERT INTO "migrations".*?;/s', $this->sql, $insert);
        $rowCount = substr_count($insert[0], "\n    (");

        $this->assertGreaterThan(100, $rowCount, 'Suspiciously few migration rows restored.');
    }

    public function test_it_is_wrapped_in_a_single_transaction(): void
    {
        // PostgreSQL DDL is transactional: if statement 400 fails, the database
        // is left untouched rather than half-built.
        $this->assertStringContainsString("BEGIN;\n", $this->sql);
        $this->assertStringEndsWith("COMMIT;\n", $this->sql);
    }

    public function test_it_declares_no_destructive_statements(): void
    {
        // The file is loaded by `migrate` against what should be an empty
        // database. A stray DROP would make running it against a populated one
        // catastrophic rather than merely an error.
        $this->assertStringNotContainsString('DROP TABLE', $this->sql);
        $this->assertStringNotContainsString('TRUNCATE', $this->sql);
    }

    // ------------------------------------------------------------------
    // Parity with the live MySQL schema
    // ------------------------------------------------------------------

    public function test_it_matches_the_live_mysql_schema(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Parity check needs the MySQL source schema.');
        }

        $schema = DB::getDatabaseName();

        $live = [];

        foreach (DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$schema]
        ) as $row) {
            $live[$row->TABLE_NAME][] = $row->COLUMN_NAME;
        }

        $generated = $this->tables();

        $missingTables = array_diff(array_keys($live), array_keys($generated));
        $this->assertSame([], array_values($missingTables),
            'Tables in MySQL but not in the baseline: '.implode(', ', $missingTables)
            .' — re-run php artisan schema:export-pgsql.');

        foreach ($live as $table => $columns) {
            $missingColumns = array_diff($columns, array_keys($generated[$table] ?? []));

            $this->assertSame([], array_values($missingColumns),
                "Columns missing from baseline table {$table}: ".implode(', ', $missingColumns));
        }
    }
}
