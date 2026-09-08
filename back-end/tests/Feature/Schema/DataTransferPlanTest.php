<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the load order `data:transfer` computes.
 *
 * The transfer itself cannot be tested here — it needs a live PostgreSQL target,
 * and neither this machine nor CI has one. What CAN be tested is the part that
 * decides everything: the order rows are inserted in. PostgreSQL checks foreign
 * keys on insert and will not let that be deferred (SET CONSTRAINTS ALL DEFERRED
 * needs DEFERRABLE constraints; DISABLE TRIGGER ALL needs superuser, which
 * Aiven's avnadmin is not), so a child inserted before its parent is a hard
 * failure halfway through the load.
 *
 * These tests read the plan out of `--dry-run`, which touches nothing.
 */
class DataTransferPlanTest extends TestCase
{
    /**
     * Runs the command and returns the plan it printed.
     *
     * Via --sql rather than --dry-run: --dry-run still tries to open the target
     * so it can report whether it is reachable, and where PG_* points at a real
     * but unreachable host that is a 30-second wait per call. --sql never
     * connects, and prints the same plan.
     */
    private function plan(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transfer').'.sql';

        try {
            Artisan::call('data:transfer', ['--sql' => $path]);

            return Artisan::output();
        } finally {
            @unlink($path);
        }
    }

    /** @return list<string> the tables in the order the command would load them */
    private function loadOrder(): array
    {
        $order = [];

        foreach (explode("\n", $this->plan()) as $line) {
            // "   1. ac_type_lookup                      7  (1 bool)"
            if (preg_match('/^\s+\d+\.\s+(\S+)/', $line, $m)) {
                $order[] = $m[1];
            }
        }

        $this->assertNotEmpty($order, 'the dry run printed no load order');

        return $order;
    }

    public function test_every_table_is_loaded_after_the_tables_it_references(): void
    {
        $order = $this->loadOrder();
        $position = array_flip($order);

        $foreignKeys = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [DB::getDatabaseName()]
        );

        $checked = 0;

        foreach ($foreignKeys as $fk) {
            $child = $fk->TABLE_NAME;
            $parent = $fk->REFERENCED_TABLE_NAME;

            // Tables the command deliberately skips (sessions, cache, ...) are
            // absent from the plan; a constraint touching one is not its problem.
            if (! isset($position[$child], $position[$parent])) {
                continue;
            }

            // Self-references are broken by nulling the column and filling it in
            // on a second pass, so they are exempt from ordering by design.
            if ($child === $parent) {
                continue;
            }

            $this->assertLessThan(
                $position[$child],
                $position[$parent],
                "{$child} is loaded before {$parent}, which it references "
                ."via {$child}.{$fk->COLUMN_NAME}"
            );

            $checked++;
        }

        $this->assertGreaterThan(100, $checked, 'far fewer foreign keys than this schema has');
    }

    public function test_the_self_referencing_tables_are_deferred(): void
    {
        $output = $this->plan();

        // These two would deadlock a naive dependency sort. If a third ever
        // appears, it shows up here rather than mid-transfer.
        $this->assertStringContainsString('locations.parent_location_id deferred', $output);
        $this->assertStringContainsString('transfers.transfer_request_id deferred', $output);
    }

    /**
     * --sql writes the same load as a script, for when the machine holding the
     * data cannot reach the target at all. It never connects, so unlike the
     * live path it can be tested here end to end — everything except whether
     * PostgreSQL accepts the result.
     */
    public function test_the_generated_script_is_a_single_verified_transaction(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'transfer').'.sql';

        try {
            Artisan::call('data:transfer', ['--sql' => $path]);

            $this->assertFileExists($path);
            $sql = file_get_contents($path);

            // One transaction, so a failure part-way leaves nothing behind.
            $this->assertSame(1, substr_count($sql, "\nBEGIN;"));
            $this->assertSame(1, substr_count($sql, "\nCOMMIT;"));
            $this->assertStringContainsString('ON_ERROR_STOP on', $sql);

            // Backslashes in the data must be literal, not escapes.
            $this->assertStringContainsString('SET standard_conforming_strings TO on;', $sql);

            // Every table is emptied before being reloaded, and the assertions
            // at the end RAISE rather than letting a short load commit.
            $this->assertStringContainsString('DELETE FROM "products";', $sql);
            $this->assertStringContainsString('INSERT INTO "products"', $sql);
            $this->assertStringContainsString('RAISE EXCEPTION', $sql);
            $this->assertStringContainsString('setval(pg_get_serial_sequence', $sql);

            // The transient tables stay out of the script for the same reasons
            // they stay out of the live path.
            $this->assertStringNotContainsString('DELETE FROM "migrations";', $sql);
            $this->assertStringNotContainsString('INSERT INTO "sessions"', $sql);

            // MySQL hands back 0/1 for tinyint(1); PostgreSQL rejects both for a
            // boolean column, so the script must carry true/false.
            $this->assertMatchesRegularExpression('/INSERT INTO "users".*\n.*(true|false)/', $sql);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_generated_script_deletes_children_before_parents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'transfer').'.sql';

        try {
            Artisan::call('data:transfer', ['--sql' => $path]);
            $sql = file_get_contents($path);

            // The DELETEs run in reverse load order. products is referenced by
            // inventory, so inventory must be emptied first or the DELETE is
            // refused by the foreign key.
            $inventory = strpos($sql, 'DELETE FROM "inventory";');
            $products = strpos($sql, 'DELETE FROM "products";');

            $this->assertNotFalse($inventory);
            $this->assertNotFalse($products);
            $this->assertLessThan($products, $inventory);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_migrations_table_is_never_copied(): void
    {
        $order = $this->loadOrder();

        // The target's migrations table is what tells Laravel the historical
        // migrations are already applied. Overwriting it with this machine's
        // copy is wrong the moment the two drift.
        $this->assertNotContains('migrations', $order);
        $this->assertNotContains('sessions', $order);
        $this->assertNotContains('personal_access_tokens', $order);
    }
}
