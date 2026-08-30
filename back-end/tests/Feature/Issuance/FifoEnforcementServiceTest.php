<?php

namespace Tests\Feature\Issuance;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Services\FifoEnforcementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * FIFO used to be ledger-deep only: deductFifo() consumed the oldest batch on
 * the books no matter which physical box the picker actually grabbed. These
 * cover the shelf-level rule, plus the three ways it can deadlock issuance —
 * the legacy catch-all batch, a quarantined oldest batch, and tied timestamps.
 */
class FifoEnforcementServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $this->product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
    }

    private function receivingId(): int
    {
        return (int) Receiving::create([
            'receiving_number' => 'RCV-' . uniqid(),
            'location_id'      => $this->location->location_id,
            'receiving_date'   => '2026-03-01',
        ])->receiving_id;
    }

    private function batch(string $receivedAt, int $remaining = 100, string $zone = 'A-03'): InventoryBatch
    {
        return InventoryBatch::create([
            'product_id' => $this->product->product_id, 'location_id' => $this->location->location_id,
            'receiving_id' => $this->receivingId(), 'quantity_received' => 100, 'quantity_remaining' => $remaining,
            'received_at' => $receivedAt, 'zone_label' => $zone,
        ]);
    }

    private function check(?int $batchId): ?array
    {
        return app(FifoEnforcementService::class)->check(
            (int) $this->product->product_id,
            (int) $this->location->location_id,
            $batchId
        );
    }

    /** Quarantine $qty pieces of $batch under an unsettled report. */
    private function quarantine(InventoryBatch $batch, int $qty): void
    {
        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $this->location->location_id, 'product_id' => $this->product->product_id,
            'reason' => 'defective', 'quantity' => $qty, 'narrative' => 'test',
            'reported_by' => (int) \App\Models\User::query()->firstOrFail()->getKey(),
            'status_id' => IncidentReport::statusId('Open'),
        ]);

        IncidentItem::create([
            'incident_id' => $incident->incident_id,
            'product_id'  => $this->product->product_id,
            'batch_id'    => $batch->batch_id,
            'is_inbound'  => false,
            'quantity'    => $qty,
            'status'      => IncidentItem::STATUS_QUARANTINED,
        ]);
    }

    public function test_picking_the_oldest_batch_is_allowed(): void
    {
        $old = $this->batch('2026-03-01');
        $this->batch('2026-04-01');

        $this->assertNull($this->check((int) $old->batch_id));
    }

    public function test_picking_a_newer_batch_is_refused(): void
    {
        $old = $this->batch('2026-03-01', 100, 'A-03');
        $new = $this->batch('2026-04-01', 100, 'B-11');

        $violation = $this->check((int) $new->batch_id);

        $this->assertIsArray($violation);
        $this->assertSame('fifo_violation', $violation['action']);
        $this->assertSame((int) $old->batch_id, $violation['expected_batch']['batch_id']);
        $this->assertSame(
            'A-03',
            $violation['expected_batch']['zone_label'],
            'A refusal must tell staff WHERE the correct stock is, or the rule gets worked around.'
        );
        $this->assertSame((int) $new->batch_id, $violation['scanned_batch']['batch_id']);
    }

    public function test_the_legacy_batch_never_blocks(): void
    {
        // receiving_id null AND received_at null — MySQL sorts NULL first on ASC,
        // so it always looks oldest, and no digital tag can ever point at it.
        InventoryBatch::create([
            'product_id' => $this->product->product_id, 'location_id' => $this->location->location_id,
            'receiving_id' => null, 'quantity_received' => 0, 'quantity_remaining' => 50,
            'received_at' => null, 'zone_label' => null,
        ]);
        $real = $this->batch('2026-04-01');

        $this->assertNull(
            $this->check((int) $real->batch_id),
            'The legacy catch-all batch has no tag, so no scan could ever satisfy it — it must never be the expected batch.'
        );
    }

    public function test_an_exhausted_older_batch_does_not_block(): void
    {
        $this->batch('2026-03-01', 0);
        $new = $this->batch('2026-04-01', 100);

        $this->assertNull($this->check((int) $new->batch_id));
    }

    public function test_a_fully_quarantined_oldest_batch_is_skipped(): void
    {
        $old = $this->batch('2026-03-01', 100);
        $new = $this->batch('2026-04-01', 100);
        $this->quarantine($old, 100);

        $this->assertNull(
            $this->check((int) $new->batch_id),
            'Stock under an unsettled incident cannot be issued, so it must not block everything behind it.'
        );
    }

    public function test_a_partially_quarantined_batch_is_skipped_to_match_picking(): void
    {
        $old = $this->batch('2026-03-01', 100);
        $new = $this->batch('2026-04-01', 100);
        $this->quarantine($old, 40);

        // 60 of the 100 are arguably still pickable, but that is NOT the rule, because
        // it is not the rule FifoPickingService uses. quarantinedBatchIds() drops a
        // batch wholesale the moment any item on it is quarantined, so picking never
        // offers this batch at all. If enforcement applied the finer quantity rule it
        // would demand the one batch the picker cannot be given — no legal move except
        // an override, every single time, which is how staff learn to override blind.
        $this->assertNull(
            $this->check((int) $new->batch_id),
            'Enforcement must never demand a batch FifoPickingService excludes from its suggestions.'
        );
    }

    public function test_tied_timestamps_resolve_deterministically(): void
    {
        $first  = $this->batch('2026-03-01 08:00:00');
        $second = $this->batch('2026-03-01 08:00:00');

        $this->assertLessThan($second->batch_id, $first->batch_id);

        // Repeat: an unstable tie-break flips between identical queries.
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($this->check((int) $first->batch_id));

            $violation = $this->check((int) $second->batch_id);
            $this->assertIsArray($violation);
            $this->assertSame((int) $first->batch_id, $violation['expected_batch']['batch_id']);
        }
    }

    public function test_a_null_scanned_batch_is_allowed_through(): void
    {
        $this->batch('2026-03-01');

        $this->assertNull($this->check(null));
    }
}
