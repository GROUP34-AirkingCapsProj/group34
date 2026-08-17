<?php

namespace Tests\Feature\Incidents;

use ReflectionMethod;
use Tests\TestCase;

/**
 * The stock_holds backfill must not strand an item under a closed report.
 *
 * The backfill mapped a still-`held` hold to `quarantined` without ever looking
 * at the parent report. Under the OLD approve-then-inspect order that was
 * coherent — Resolved meant "approved", and the hold was genuinely still
 * awaiting disposal. Under the new order it is a state the model cannot
 * express: `quarantined` means "somebody still has to decide", and a closed
 * report has no screen left to decide it on.
 *
 * The damage is silent and permanent. quarantinedBatchIds() keeps excluding the
 * batch from FIFO picking, quarantinedQuantities() keeps subtracting the
 * pieces, and nothing in the UI can ever release them. It happened on this
 * install: three items under Resolved reports held 57 pieces of one batch out
 * of the picking pool, discovered only because a user asked why inventory
 * looked wrong.
 *
 * Tested through reflection on the decision function rather than by running
 * the migration: up() does CREATE/DROP TABLE, and DDL implicitly commits in
 * MySQL, which would break the surrounding test transaction and leak rows.
 */
class StockHoldBackfillGuardTest extends TestCase
{
    private function decide(array $hold, ?string $closedAs): string
    {
        $migration = require base_path(
            'database/migrations/2026_08_12_000004_migrate_stock_holds_to_incident_items.php'
        );

        $method = new ReflectionMethod($migration, 'statusFor');
        $method->setAccessible(true);

        return $method->invoke($migration, (object) $hold, $closedAs);
    }

    /** A hold still awaiting a disposition, under a report still in flight. */
    private function heldHold(array $over = []): array
    {
        return array_merge([
            'status'      => 'held',
            'disposition' => null,
            'condition'   => 'defective',
        ], $over);
    }

    public function test_an_open_report_still_yields_a_quarantined_item(): void
    {
        $this->assertSame('quarantined', $this->decide($this->heldHold(), null));
    }

    /**
     * A dismissal releases everything — that is exactly what
     * IncidentItemService::release() does, and the backfill should land on the
     * same answer rather than inventing a hold nobody can clear.
     */
    public function test_a_dismissed_report_releases_the_item_instead_of_stranding_it(): void
    {
        $this->assertSame('available', $this->decide($this->heldHold(), 'Dismissed'));
    }

    /**
     * Resolved with a disposition recorded: settle it the way confirming would
     * have, via IncidentItem::OUTCOME_STATUS.
     */
    public function test_a_resolved_report_settles_a_missing_hold_as_missing(): void
    {
        $hold = $this->heldHold(['condition' => 'missing']);

        $this->assertSame('missing', $this->decide($hold, 'Resolved'));
    }

    public function test_a_resolved_report_settles_a_scrapped_hold_as_scrapped(): void
    {
        $hold = $this->heldHold(['status' => 'released', 'disposition' => 'scrapped']);

        $this->assertSame('scrapped', $this->decide($hold, 'Resolved'));
    }

    /**
     * The genuinely unmappable case: closed, but nothing on the hold says what
     * happened. The migration does NOT invent a settlement — it leaves the item
     * quarantined and warns, so a human decides rather than the migration
     * guessing at stock. What it must never do is leave it silently.
     */
    public function test_a_resolved_report_with_nothing_recorded_stays_quarantined_to_be_reviewed(): void
    {
        $this->assertSame('quarantined', $this->decide($this->heldHold(), 'Resolved'));
    }

    /** Dispositions that already mapped keep mapping, closed or not. */
    public function test_the_existing_mapping_is_unchanged(): void
    {
        $restocked = ['status' => 'released', 'disposition' => 'restocked', 'condition' => 'good'];
        $returned  = ['status' => 'released', 'disposition' => 'returned_to_supplier', 'condition' => 'defective'];

        $this->assertSame('available', $this->decide($restocked, null));
        $this->assertSame('available', $this->decide($restocked, 'Resolved'));
        $this->assertSame('returned', $this->decide($returned, null));
        $this->assertSame('returned', $this->decide($returned, 'Resolved'));
    }
}
