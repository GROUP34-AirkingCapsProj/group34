<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentReport;
use App\Services\FifoPickingService;
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
            'reason'        => IncidentReport::REASON_NOT_FOUND,
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
            'batch_id'    => $old->batch_id, 'reason' => IncidentReport::REASON_NOT_FOUND,
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
            'batch_id'    => $batch->batch_id, 'reason' => IncidentReport::REASON_DAMAGED,
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
            'reason'      => IncidentReport::REASON_NOT_FOUND, 'quantity' => 1,
            'narrative'   => 'Whole product missing.',
        ], (int) $user->user_id);

        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
    }

    public function test_a_non_open_incident_no_longer_excludes_its_batch(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $b->batch_id, 'reason' => IncidentReport::REASON_DAMAGED,
            'quantity'    => 1, 'narrative' => 'Crushed.',
        ], (int) $user->user_id);

        $this->assertContains((int) $b->batch_id, app(IncidentService::class)->excludedBatchIds($loc->location_id));

        // Simulate resolution (Task 19 builds the real resolve()). Once not Open,
        // the batch returns to FIFO rotation with no cleanup step.
        $incident->update(['status_id' => IncidentReport::statusId('Resolved')]);

        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
    }
}
