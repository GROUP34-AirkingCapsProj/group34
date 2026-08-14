<?php

namespace Tests\Feature\Incidents;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\IncidentReport;
use App\Services\IncidentService;
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
 *
 * NOTE: runMigration() hardcodes the migration's filename. If the migrations
 * are ever squashed or `schema:dump --prune` runs, delete this test with them.
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
     *
     * The echo is captured rather than silenced: it earns its place on a real
     * deploy, but it should not spray three stray lines across the suite, and
     * the counts are worth asserting on.
     */
    private function runMigration(): string
    {
        $migration = require base_path(
            'database/migrations/2026_08_14_000001_split_awaiting_inspection_status.php'
        );

        ob_start();

        try {
            $migration->up();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    /**
     * Drop the status so the migration has to create it.
     *
     * Without this the assertion passes on an environment that already has the
     * row — the migration ran against airkingV5_test and the seeder inserts it
     * too — and would survive deleting the updateOrInsert outright.
     */
    private function forgetAwaitingApproval(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'incident')
            ->where('status_name', 'Awaiting Approval')
            ->delete();
    }

    /** A real user id — the seeded ids differ between the dev and test databases. */
    private function actorId(): int
    {
        return (int) \App\Models\User::query()->firstOrFail()->getKey();
    }

    private function inventoryStatusId(string $name): int
    {
        return (int) DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');
    }

    /**
     * File through IncidentService, the way production makes a report.
     *
     * Modelled on IncidentDecisionOrderTest::fileIncident() for the same
     * reason it exists there: a hand-built row encodes the test's assumption
     * about the shape rather than the shape the service actually writes.
     */
    private function fileIncident(): IncidentReport
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'status_id'          => $this->inventoryStatusId('In Stock'),
            'quantity_on_hand'   => 100,
            'available_quantity' => 100,
            'reorder_level'      => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_received'  => 100,
            'quantity_remaining' => 100,
            'received_at'        => now(),
            'zone_label'         => 'ZONE-1',
        ]);

        return app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => (int) $location->location_id,
            'product_id'  => (int) $product->product_id,
            'batch_id'    => (int) $batch->batch_id,
            'reason'      => 'return',
            'quantity'    => 1,
            'narrative'   => 'test',
        ], $this->actorId());
    }

    /** Shape 1: the inspector submitted, so the ADMIN owes a decision. */
    private function submittedReport(): IncidentReport
    {
        $incident = $this->fileIncident();

        return app(IncidentService::class)
            ->submitInspection($incident, [], $this->actorId());
    }

    /** Shape 2: the admin sent it back, so it stays with the INSPECTOR. */
    private function sentBackReport(): IncidentReport
    {
        $incident = $this->submittedReport();

        return app(IncidentService::class)->decide(
            $incident->fresh(),
            'reject',
            ['rejection_note' => 'the sticker photo is unreadable'],
            $this->actorId(),
        );
    }

    /**
     * Shape 3: at Awaiting Inspection but never inspected.
     *
     * Built by hand because no service path produces it — file() leaves a
     * report at Open. It should not exist, and the rule declines to guess if
     * it does, so it is worth pinning anyway.
     */
    private function neverInspectedReport(): int
    {
        return (int) DB::table('incident_reports')->insertGetId([
            'incident_number' => 'INC-TEST-' . bin2hex(random_bytes(4)),
            'source_type'     => 'standalone',
            'location_id'     => \App\Models\Location::query()->firstOrFail()->getKey(),
            'reported_by'     => $this->actorId(),
            'reason'          => 'return',
            'quantity'        => 1,
            'status_id'       => $this->incidentStatusId('Awaiting Inspection'),
            'inspected_at'    => null,
            'rejection_note'  => null,
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

    /**
     * The migration must insert the status itself.
     *
     * Deliberately not left to IncidentStatusSeeder: a live deploy is not
     * guaranteed to re-seed, and IncidentReport::statusId() throws on a
     * missing name, so the first submitted inspection after deploy would 500.
     */
    public function test_up_inserts_the_awaiting_approval_status(): void
    {
        $this->forgetAwaitingApproval();

        $this->assertSame(0, $this->incidentStatusId('Awaiting Approval'), 'fixture: the row must be gone first');

        $this->runMigration();

        $this->assertNotSame(
            0,
            $this->incidentStatusId('Awaiting Approval'),
            'The migration must insert Awaiting Approval — IncidentReport::statusId() throws on a missing name.'
        );
    }

    /**
     * The migration's own UPDATE, run against all three row shapes.
     *
     * Deleting either `whereNotNull('inspected_at')` or
     * `whereNull('rejection_note')` from the migration must break this test.
     */
    public function test_up_routes_each_row_shape_to_its_owner(): void
    {
        $submitted      = $this->submittedReport();
        $sentBack       = $this->sentBackReport();
        $neverInspected = $this->neverInspectedReport();

        // The service must actually produce the shapes the rule keys on, or
        // the migration is sorting rows that production never writes.
        $this->assertNotNull($submitted->inspected_at);
        $this->assertNull($submitted->rejection_note);
        $this->assertNotNull($sentBack->rejection_note);

        $output = $this->runMigration();

        $this->assertSame('Awaiting Approval', $this->statusNameOf((int) $submitted->incident_id));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf((int) $sentBack->incident_id));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf($neverInspected));

        $this->assertStringContainsString('moved 1 to Awaiting Approval', $output);
        $this->assertStringContainsString('left 2 with the inspector', $output);
    }

    /**
     * Running up() twice is a no-op the second time.
     *
     * Both halves are idempotent or the migration is not: the UPDATE must find
     * nothing left to move, and the updateOrInsert must not add a second
     * `Awaiting Approval` row. The status is dropped first so the count below
     * distinguishes "inserted exactly once" from "never inserted at all".
     */
    public function test_up_is_idempotent(): void
    {
        $this->forgetAwaitingApproval();

        $submitted      = $this->submittedReport();
        $sentBack       = $this->sentBackReport();
        $neverInspected = $this->neverInspectedReport();

        $this->runMigration();
        $second = $this->runMigration();

        $this->assertSame('Awaiting Approval', $this->statusNameOf((int) $submitted->incident_id));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf((int) $sentBack->incident_id));
        $this->assertSame('Awaiting Inspection', $this->statusNameOf($neverInspected));

        $this->assertStringContainsString('moved 0 to Awaiting Approval', $second);

        $this->assertSame(
            1,
            DB::table('status_lookup')
                ->where('status_category', 'incident')
                ->where('status_name', 'Awaiting Approval')
                ->count(),
            'updateOrInsert keys on (status_category, status_name): one row after two runs, not zero and not two.'
        );
    }
}
