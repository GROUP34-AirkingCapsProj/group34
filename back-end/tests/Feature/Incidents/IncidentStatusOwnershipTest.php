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
    private function fileIncident(string $reason = 'return'): IncidentReport
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
            'reason'      => $reason,
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
     * Shape 1 as the OLD code left it on disk.
     *
     * The migration is a one-time fix for rows written before this split, and
     * submitInspection() no longer writes any: it goes straight to Awaiting
     * Approval. So the row is built by the service — which is what guarantees
     * the inspected_at / null-note shape the migration's rule keys on is real —
     * and only its status is wound back to what the old write produced.
     */
    private function legacySubmittedReport(): IncidentReport
    {
        $submitted = $this->submittedReport();

        DB::table('incident_reports')
            ->where('incident_id', $submitted->incident_id)
            ->update(['status_id' => $this->incidentStatusId('Awaiting Inspection')]);

        return $submitted->fresh();
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
        $submitted      = $this->legacySubmittedReport();
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
     * `Awaiting Approval` row. The status is dropped before the runs so the
     * count below distinguishes "inserted exactly once" from "never inserted at
     * all" — but AFTER the fixtures, because submitInspection() now writes
     * Awaiting Approval and cannot build a legacy row without it.
     */
    public function test_up_is_idempotent(): void
    {
        $submitted      = $this->legacySubmittedReport();
        $sentBack       = $this->sentBackReport();
        $neverInspected = $this->neverInspectedReport();

        // Safe only because nothing above is left pointing at the row.
        $this->forgetAwaitingApproval();

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

    public function test_submitting_an_inspection_hands_the_report_to_the_admin(): void
    {
        $submitted = $this->submittedReport();

        $this->assertSame(
            $this->incidentStatusId('Awaiting Approval'),
            (int) $submitted->status_id,
            'A submitted inspection is the ADMIN\'s turn, not the inspector\'s.'
        );
    }

    public function test_rejecting_hands_the_report_back_to_the_inspector(): void
    {
        $rejected = $this->sentBackReport();

        $this->assertSame(
            $this->incidentStatusId('Awaiting Inspection'),
            (int) $rejected->status_id,
            'A rejected report is the INSPECTOR\'s turn.'
        );
        // Exact, not assertNotNull: the blank-note test pins trim()'s role as a
        // gate, but nothing would notice it failing to NORMALISE — a padded or
        // garbage note would persist unremarked.
        $this->assertSame('the sticker photo is unreadable', $rejected->rejection_note);
    }

    /** trim() normalises what it stores, it does not only decide whether to store. */
    public function test_a_padded_note_is_stored_trimmed(): void
    {
        $submitted = $this->submittedReport();

        $rejected = app(IncidentService::class)->decide(
            $submitted,
            'reject',
            ['rejection_note' => '   redo the box photo   '],
            $this->actorId(),
        );

        $this->assertSame('redo the box photo', $rejected->rejection_note);
    }

    /**
     * `resolution_note` must NOT satisfy the rejection note.
     *
     * It is the field confirm() and dismiss() write when they CLOSE a report.
     * Accepting it here would let a caller outside the controller send a report
     * back badged "Sent back: recount came out fine" — closing text reading as
     * a fix instruction — and satisfy the non-null-note guard while doing it,
     * turning the refusal above into a silent wrong value.
     */
    public function test_a_resolution_note_does_not_satisfy_the_rejection_note(): void
    {
        $submitted = $this->submittedReport();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a note');

        app(IncidentService::class)->decide(
            $submitted,
            'reject',
            ['resolution_note' => 'recount came out fine'],
            $this->actorId(),
        );
    }

    /**
     * A decided report takes no further submission.
     *
     * Before the split this could not be expressed: there was no status meaning
     * "already with the admin", so the submit guard's list was the whole of the
     * inspector's world. Now it is load-bearing, and untested it stayed green
     * with the guard disabled outright.
     *
     * The scenario is an inspector whose page is still open when the admin
     * confirms. Stock is not double-deducted — IncidentItemService catches that
     * under its own lock — but a Resolved report would jump back to Awaiting
     * Approval, overwrite inspected_by/inspected_at, null the note, and
     * reappear on the admin's Decide queue.
     */
    public function test_a_confirmed_report_cannot_be_submitted_again(): void
    {
        $submitted = $this->submittedReport();

        // A real confirmation, not an exempt shortcut: applyOutcomes refuses an
        // item with no outcome, and going through it is what makes this the
        // stock-moving path the guard is protecting.
        app(\App\Services\IncidentItemService::class)->setOutcome(
            \App\Models\IncidentItem::where('incident_id', $submitted->incident_id)->firstOrFail(),
            \App\Models\IncidentItem::OUTCOME_SCRAP,
            null,
        );

        $confirmed = app(IncidentService::class)
            ->decide($submitted->fresh(), 'confirm', [], $this->actorId());

        $this->assertSame($this->incidentStatusId('Resolved'), (int) $confirmed->status_id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been decided');

        app(IncidentService::class)->submitInspection($confirmed, [], $this->actorId());
    }

    public function test_resubmitting_a_rejected_report_returns_it_to_the_admin(): void
    {
        $rejected = $this->sentBackReport();

        $resubmitted = app(IncidentService::class)->submitInspection(
            $rejected,
            ['inspection_findings' => 'fixed what the note asked for'],
            $this->actorId(),
        );

        $this->assertSame(
            $this->incidentStatusId('Awaiting Approval'),
            (int) $resubmitted->status_id
        );
        // The note answered its round; leaving it would have the admin's own
        // screen accuse a fixed report of being rejected.
        $this->assertNull($resubmitted->rejection_note);
    }

    /**
     * The guard the old model could not express.
     *
     * A sent-back report and a submitted one shared a status, so "confirm what
     * I just rejected" was indistinguishable from "confirm a real inspection"
     * — and confirming is the single moment stock moves.
     */
    public function test_an_admin_cannot_confirm_a_report_they_just_sent_back(): void
    {
        $rejected = $this->sentBackReport();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has not been inspected yet');

        app(IncidentService::class)->decide($rejected, 'confirm', [], $this->actorId());
    }

    public function test_rejecting_a_report_with_no_submitted_inspection_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no submitted inspection to reject');

        app(IncidentService::class)->decide(
            $this->fileIncident(),
            'reject',
            ['rejection_note' => 'nope'],
            $this->actorId(),
        );
    }

    /**
     * A zone mismatch creates no items and is never inspected, so it is
     * confirmed straight from Open. Splitting the inspected statuses must not
     * catch it in the new guard.
     */
    public function test_an_exempt_reason_still_confirms_straight_from_open(): void
    {
        $confirmed = app(IncidentService::class)
            ->decide($this->fileIncident('zone_mismatch'), 'confirm', [], $this->actorId());

        $this->assertSame($this->incidentStatusId('Resolved'), (int) $confirmed->status_id);
    }

    /**
     * The migration's routing rule reads a non-null rejection_note as "sent
     * back". That was a CONTROLLER invariant — IncidentInspectionController
     * applies required_if:action,reject — while the service itself would
     * persist NULL from `$options['rejection_note'] ?? $options['resolution_note'] ?? null`.
     *
     * A second rejection path that skipped the controller (a console command, a
     * bulk action) would leave a NULL-note report at Awaiting Inspection,
     * indistinguishable from a submitted one. Enforced here so the invariant
     * belongs to the layer that depends on it.
     */
    public function test_rejecting_without_a_note_is_refused(): void
    {
        $submitted = $this->submittedReport();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a note');

        app(IncidentService::class)->decide($submitted, 'reject', [], $this->actorId());
    }

    public function test_rejecting_with_a_blank_note_is_refused(): void
    {
        $submitted = $this->submittedReport();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a note');

        app(IncidentService::class)->decide($submitted, 'reject', ['rejection_note' => '   '], $this->actorId());
    }

    /**
     * The admin's worklist filter.
     *
     * `scopeOpen()` is status Open ALONE. Since the split, a report submitted
     * for the admin's decision is Awaiting Approval — so a worklist built on
     * `open()` hides exactly the reports it exists to surface. With no Open
     * reports on the books it renders an empty list while inspections wait,
     * which is what the live data looked like: 0 Open, 2 Awaiting Approval.
     */
    public function test_the_unfinished_scope_includes_a_report_waiting_on_the_admin(): void
    {
        $submitted = $this->submittedReport();

        $unfinished = IncidentReport::query()->unfinished()->pluck('incident_id')->all();
        $openOnly   = IncidentReport::query()->open()->pluck('incident_id')->all();

        $this->assertContains((int) $submitted->incident_id, array_map('intval', $unfinished));
        $this->assertNotContains(
            (int) $submitted->incident_id,
            array_map('intval', $openOnly),
            'scopeOpen is Open alone — this is why the admin needed a wider filter.'
        );
    }

    public function test_the_unfinished_scope_includes_a_report_sent_back_to_the_inspector(): void
    {
        $rejected = $this->sentBackReport();

        $this->assertContains(
            (int) $rejected->incident_id,
            array_map('intval', IncidentReport::query()->unfinished()->pluck('incident_id')->all())
        );
    }

    /** Finished is finished: a decided report drops out of every worklist. */
    public function test_the_unfinished_scope_excludes_a_decided_report(): void
    {
        $dismissed = app(IncidentService::class)
            ->decide($this->fileIncident(), 'dismiss', [], $this->actorId());

        $this->assertSame($this->incidentStatusId('Dismissed'), (int) $dismissed->status_id);
        $this->assertNotContains(
            (int) $dismissed->incident_id,
            array_map('intval', IncidentReport::query()->unfinished()->pluck('incident_id')->all())
        );
    }
}
