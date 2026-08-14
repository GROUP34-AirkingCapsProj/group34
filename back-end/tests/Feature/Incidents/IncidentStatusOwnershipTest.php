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

    /**
     * The migration file returns an anonymous class instance, so a test can
     * require it and drive up() directly. Under DatabaseTransactions the rows
     * it touches roll back with everything else, which is what lets these
     * tests exercise the real statement instead of restating its WHERE clause.
     */
    private function runMigration(): void
    {
        $migration = require base_path(
            'database/migrations/2026_08_14_000001_split_awaiting_inspection_status.php'
        );

        $migration->up();
    }

    /**
     * A report at `Awaiting Inspection` in one of the three shapes up() sorts.
     *
     * Only the columns the rule reads are interesting; the rest are here
     * because the table demands them. location_id and reported_by are resolved
     * from real rows rather than written as literals, since the seeded ids
     * differ between the dev and test databases.
     */
    private function reportAwaitingInspection(?string $inspectedAt, ?string $rejectionNote): int
    {
        return (int) DB::table('incident_reports')->insertGetId([
            'incident_number' => 'INC-TEST-' . bin2hex(random_bytes(4)),
            'source_type'     => 'standalone',
            'location_id'     => \App\Models\Location::query()->firstOrFail()->getKey(),
            'reported_by'     => \App\Models\User::query()->firstOrFail()->getKey(),
            'reason'          => 'return',
            'quantity'        => 1,
            'status_id'       => $this->incidentStatusId('Awaiting Inspection'),
            'inspected_at'    => $inspectedAt,
            'rejection_note'  => $rejectionNote,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function statusNameOf(int $incidentId): string
    {
        return (string) DB::table('incident_reports')
            ->join('status_lookup', 'status_lookup.status_id', '=', 'incident_reports.status_id')
            ->where('incident_reports.incident_id', $incidentId)
            ->value('status_lookup.status_name');
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
     * The migration's own UPDATE, run against all three row shapes.
     *
     * Deleting `whereNotNull('inspected_at')` from the migration must break
     * this test — that is the check that it is testing the rule and not a
     * paraphrase of it.
     */
    public function test_up_routes_each_row_shape_to_its_owner(): void
    {
        // Inspected, never sent back → the admin owes a decision.
        $submitted = $this->reportAwaitingInspection('2026-08-14 05:14:24', null);

        // Sent back → stays with the inspector. submitInspection() nulls the
        // note, so a surviving note can only mean "not yet resubmitted".
        $sentBack = $this->reportAwaitingInspection('2026-08-14 05:14:24', 'the sticker photo is unreadable');

        // Never inspected → stays with the inspector. Should not exist at this
        // status, and the rule declines to guess if it does.
        $neverInspected = $this->reportAwaitingInspection(null, null);

        $this->runMigration();

        $this->assertSame('Awaiting Approval', $this->statusNameOf($submitted));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf($sentBack));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf($neverInspected));
    }

    /**
     * Running up() twice is a no-op the second time.
     *
     * Both halves are idempotent or the migration is not: the UPDATE must find
     * nothing left to move, and the updateOrInsert must not leave a second
     * `Awaiting Approval` row behind.
     */
    public function test_up_is_idempotent(): void
    {
        $submitted      = $this->reportAwaitingInspection('2026-08-14 05:14:24', null);
        $sentBack       = $this->reportAwaitingInspection('2026-08-14 05:14:24', 'unreadable sticker');
        $neverInspected = $this->reportAwaitingInspection(null, null);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('Awaiting Approval', $this->statusNameOf($submitted));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf($sentBack));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf($neverInspected));

        $this->assertSame(
            1,
            DB::table('status_lookup')
                ->where('status_category', 'incident')
                ->where('status_name', 'Awaiting Approval')
                ->count(),
            'updateOrInsert keys on (status_category, status_name), so a second run must not add a row.'
        );
    }
}
