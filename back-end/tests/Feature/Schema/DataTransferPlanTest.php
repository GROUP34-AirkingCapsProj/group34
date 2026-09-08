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
    /** @return list<string> the tables in the order the command would load them */
    private function loadOrder(): array
    {
        Artisan::call('data:transfer', ['--dry-run' => true]);

        $order = [];

        foreach (explode("\n", Artisan::output()) as $line) {
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
        Artisan::call('data:transfer', ['--dry-run' => true]);
        $output = Artisan::output();

        // These two would deadlock a naive dependency sort. If a third ever
        // appears, it shows up here rather than mid-transfer.
        $this->assertStringContainsString('locations.parent_location_id deferred', $output);
        $this->assertStringContainsString('transfers.transfer_request_id deferred', $output);
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
