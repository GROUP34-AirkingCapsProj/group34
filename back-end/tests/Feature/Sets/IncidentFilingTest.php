<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Services\FifoPickingService;
use App\Services\IncidentItemService;
use App\Services\IncidentService;
use App\Support\ScanResult;

class IncidentFilingTest extends SetTestCase
{
    public function test_filing_an_incident_opens_it_with_a_number(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type'   => 'issuance',
            'source_id'     => 1,
            'location_id'   => $loc->location_id,
            'product_id'    => $p->product_id,
            'batch_id'      => $b->batch_id,
            'expected_zone' => 'Zone 1',
            'actual_zone'   => 'Zone 2',
            'reason'        => IncidentReport::REASON_MISSING,
            'quantity'      => 5,
            'narrative'     => 'Bin empty, nothing on the shelf.',
        ], (int) $user->user_id);

        $this->assertSame(IncidentReport::statusId('Open'), (int) $incident->status_id);
        $this->assertStringStartsWith('INC-', $incident->incident_number);
        $this->assertSame((int) $user->user_id, (int) $incident->reported_by);
    }

    public function test_an_open_incident_excludes_its_batch_from_fifo(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(barcode: 'BC-EXCL-FLOW');
        $old  = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $svc   = app(IncidentService::class);
        $fifo  = app(FifoPickingService::class);
        $lines = [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]];

        // Before: Zone 2 is out of order.
        $this->assertSame(
            ScanResult::FIFO_VIOLATION,
            $fifo->validateScan($loc->location_id, $lines, 'BC-EXCL-FLOW', 'Zone 2', null, $svc->excludedBatchIds($loc->location_id))->status
        );

        $svc->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $old->batch_id, 'reason' => IncidentReport::REASON_MISSING,
            'quantity'    => 5, 'narrative' => 'Not on the shelf.',
        ], (int) $user->user_id);

        // After: Zone 1 is excluded, so Zone 2 is now legitimately the oldest.
        $this->assertSame(
            ScanResult::ACCEPTED,
            $fifo->validateScan($loc->location_id, $lines, 'BC-EXCL-FLOW', 'Zone 2', null, $svc->excludedBatchIds($loc->location_id))->status
        );
    }

    public function test_exclusions_are_scoped_to_their_location(): void
    {
        $a    = $this->makeLocation('A');
        $b    = $this->makeLocation('B');
        $user = $this->makeUserAt($a, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $batch = $this->makeBatch($p, $a, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        app(IncidentService::class)->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $a->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $batch->batch_id, 'reason' => IncidentReport::REASON_DEFECTIVE,
            'quantity'    => 1, 'narrative' => 'Crushed.',
        ], (int) $user->user_id);

        $this->assertContains((int) $batch->batch_id, app(IncidentService::class)->excludedBatchIds($a->location_id));
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($b->location_id));
    }

    public function test_an_unknown_reason_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();

        $this->expectException(\InvalidArgumentException::class);

        app(IncidentService::class)->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'reason'      => 'because_i_said_so', 'quantity' => 1,
        ], (int) $user->user_id);
    }

    public function test_an_incident_without_a_batch_excludes_nothing(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        // A batchless incident (e.g. product-level) must not pull any batch out of rotation.
        app(IncidentService::class)->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'reason'      => IncidentReport::REASON_MISSING, 'quantity' => 1,
            'narrative'   => 'Whole product missing.',
        ], (int) $user->user_id);

        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
    }

    /**
     * INVERTED. This used to assert that moving the incident off "Open" returned
     * its batch to FIFO, because exclusion was derived from the INCIDENT's
     * status.
     *
     * That was substantively wrong, and it broke once already: adding "Awaiting
     * Inspection" silently handed stock that was under investigation back to the
     * picking pool. Exclusion now follows the ITEM's status, so a report whose
     * paperwork has moved on while its goods are still quarantined correctly
     * keeps that batch out of rotation — the batch returns when somebody has
     * actually decided what happened to the units, not when a status column
     * changes.
     */
    public function test_a_settled_status_does_not_release_a_still_quarantined_batch(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $b->batch_id, 'reason' => IncidentReport::REASON_DEFECTIVE,
            'quantity'    => 1, 'narrative' => 'Crushed.',
        ], (int) $user->user_id);

        $this->assertContains((int) $b->batch_id, app(IncidentService::class)->excludedBatchIds($loc->location_id));

        // Move the paperwork on WITHOUT settling the goods — exactly the shape
        // the old Open-only query got wrong.
        $incident->update(['status_id' => IncidentReport::statusId('Resolved')]);

        // Inverted: was assertSame([], ...). The units have had no outcome
        // applied, so they are still quarantined and the batch is still unpickable.
        $this->assertContains(
            (int) $b->batch_id,
            app(IncidentService::class)->excludedBatchIds($loc->location_id),
            'a status change alone must not return uninspected stock to picking'
        );

        // Settling the ITEM is what releases it, and it does so on its own —
        // no cleanup step, and no dependence on the incident's status.
        app(IncidentItemService::class)->setOutcome(
            IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail(),
            IncidentItem::OUTCOME_RESTOCK,
            null
        );
        app(IncidentItemService::class)->applyOutcomes($incident, (int) $user->user_id);

        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
    }
}
