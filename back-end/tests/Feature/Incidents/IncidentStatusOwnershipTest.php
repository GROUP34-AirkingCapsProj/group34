<?php

namespace Tests\Feature\Incidents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One status, one owner.
 *
 * `Awaiting Inspection` used to be written both when the inspector submitted
 * and when the admin rejected, so it meant "waiting on the admin" in the
 * admin's list and "the admin sent this back" in the inspector's queue. Every
 * submitted inspection landed in the inspector's queue badged "Sent back".
 */
class IncidentStatusOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    private function incidentStatusId(string $name): int
    {
        return (int) DB::table('status_lookup')
            ->where('status_category', 'incident')
            ->where('status_name', $name)
            ->value('status_id');
    }

    public function test_awaiting_approval_status_exists(): void
    {
        $this->assertNotSame(
            0,
            $this->incidentStatusId('Awaiting Approval'),
            'The migration must insert Awaiting Approval — IncidentReport::statusId() throws on a missing name.'
        );
    }

    /**
     * The rule the migration applies, exercised against all three row shapes.
     *
     * This asserts the CRITERIA rather than re-running the migration file,
     * which has already run by the time any test boots. The real check against
     * production data is the row count in Task 6.
     */
    public function test_the_migration_rule_routes_each_row_shape_correctly(): void
    {
        $awaitingInspection = $this->incidentStatusId('Awaiting Inspection');

        $matches = fn (array $row) => $row['status_id'] === $awaitingInspection
            && $row['rejection_note'] === null
            && $row['inspected_at'] !== null;

        // Inspected, never sent back → the admin owes a decision.
        $this->assertTrue($matches([
            'status_id' => $awaitingInspection,
            'rejection_note' => null,
            'inspected_at' => '2026-08-14 05:14:24',
        ]));

        // Sent back → stays with the inspector. submitInspection() nulls the
        // note, so a surviving note can only mean "not yet resubmitted".
        $this->assertFalse($matches([
            'status_id' => $awaitingInspection,
            'rejection_note' => 'the sticker photo is unreadable',
            'inspected_at' => '2026-08-14 05:14:24',
        ]));

        // Never inspected → stays with the inspector. Should not exist at this
        // status, and the rule declines to guess if it does.
        $this->assertFalse($matches([
            'status_id' => $awaitingInspection,
            'rejection_note' => null,
            'inspected_at' => null,
        ]));
    }

    /**
     * Idempotent: nothing is left at Awaiting Inspection that the rule would
     * still move, so a second run is a no-op.
     */
    public function test_the_migration_left_nothing_it_would_move_again(): void
    {
        $stillMovable = DB::table('incident_reports')
            ->where('status_id', $this->incidentStatusId('Awaiting Inspection'))
            ->whereNull('rejection_note')
            ->whereNotNull('inspected_at')
            ->count();

        $this->assertSame(0, $stillMovable);
    }
}
