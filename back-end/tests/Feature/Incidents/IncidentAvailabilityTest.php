<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\IncidentItemService;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Quarantined stock must stop being PROMISABLE the moment a report names it.
 *
 * Batch exclusion alone cannot carry that guarantee: an item filed without a
 * batch excludes nothing, and availability quotes read inventory rather than
 * batches. These cover the quantity that batch exclusion cannot see.
 */
class IncidentAvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    private function actorId(): int
    {
        return (int) \App\Models\User::query()->firstOrFail()->getKey();
    }

    /** @return array{location:\App\Models\Location,product:\App\Models\Product,inventory:Inventory,incident:IncidentReport} */
    private function makeScenario(int $onHand = 100): array
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        $inventory = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => $onHand, 'available_quantity' => $onHand, 'reorder_level' => 0,
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'reason' => 'defective', 'quantity' => 10, 'narrative' => 'test',
            'reported_by' => $this->actorId(), 'status_id' => IncidentReport::statusId('Open'),
        ]);

        return compact('location', 'product', 'inventory', 'incident');
    }

    /** The hole batch exclusion cannot see: no batch named, so nothing was excluded. */
    public function test_a_batchless_quarantined_item_still_reduces_availability(): void
    {
        ['product' => $p, 'incident' => $incident] = $this->makeScenario(100);

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => null, 'quantity' => 10,
        ]]);

        $held = app(IncidentService::class)->quarantinedQuantities((int) $incident->location_id);

        $this->assertSame(10, $held[(int) $p->product_id] ?? 0);
    }

    /** Inbound goods were never in the count, so they cannot be subtracted from it. */
    public function test_inbound_quarantine_does_not_reduce_availability(): void
    {
        ['product' => $p, 'incident' => $incident] = $this->makeScenario(100);

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'quantity' => 10, 'is_inbound' => true,
        ]]);

        $held = app(IncidentService::class)->quarantinedQuantities((int) $incident->location_id);

        $this->assertSame(0, $held[(int) $p->product_id] ?? 0);
    }

    /** Settling the item gives the quantity back to the pool with no cleanup step. */
    public function test_a_settled_item_no_longer_reduces_availability(): void
    {
        ['product' => $p, 'incident' => $incident] = $this->makeScenario(100);

        $items = app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => null, 'quantity' => 10,
        ]]);

        app(IncidentItemService::class)->setOutcome($items[0], IncidentItem::OUTCOME_RESTOCK, null);
        app(IncidentItemService::class)->applyOutcomes($incident, $this->actorId());

        $held = app(IncidentService::class)->quarantinedQuantities((int) $incident->location_id);

        $this->assertSame(0, $held[(int) $p->product_id] ?? 0);
    }

    /** A quarantined batch is counted too — the two mechanisms agree on the quantity. */
    public function test_a_batched_quarantined_item_is_counted_as_well(): void
    {
        ['location' => $loc, 'product' => $p, 'incident' => $incident] = $this->makeScenario(100);

        $batch = InventoryBatch::create([
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 7,
        ]]);

        $held = app(IncidentService::class)->quarantinedQuantities((int) $incident->location_id);

        $this->assertSame(7, $held[(int) $p->product_id] ?? 0);
    }

    /**
     * Someone picked the goods before the report was settled.
     *
     * The deduction can only take what is there, but the missing pieces must
     * land on the record rather than evaporate — the discrepancy is exactly what
     * the next physical count needs to be told about.
     */
    public function test_a_confirmation_shortfall_is_recorded_on_the_incident(): void
    {
        ['inventory' => $inv, 'product' => $p, 'incident' => $incident] = $this->makeScenario(100);

        $items = app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => null, 'quantity' => 10,
        ]]);

        // The stock walks out from under the open report.
        $inv->update(['quantity_on_hand' => 4, 'available_quantity' => 4]);

        app(IncidentItemService::class)->setOutcome($items[0], IncidentItem::OUTCOME_SCRAP, null);
        app(IncidentItemService::class)->applyOutcomes($incident, $this->actorId());

        $this->assertSame(0, (int) $inv->refresh()->quantity_on_hand, 'it can only take what is there');

        $note = (string) $incident->fresh()->resolution_note;
        $this->assertStringContainsString('6', $note, 'the 6 pieces it could not deduct must be on the record');
        $this->assertStringContainsString((string) $p->product_id, $note);
    }

    /**
     * Legacy stock — no batches at all — under a report that holds every piece.
     *
     * The scan path waves batchless products through because there is no FIFO
     * order to enforce. That is right up until an incident holds the lot, which
     * batch exclusion cannot express for a product that has no batches.
     */
    public function test_picking_is_blocked_when_an_incident_holds_all_batchless_stock(): void
    {
        ['location' => $loc, 'product' => $p, 'incident' => $incident] = $this->makeScenario(10);

        InventoryBatch::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->delete();

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => null, 'quantity' => 10,
        ]]);

        $result = app(\App\Services\FifoPickingService::class)->validateScan(
            (int) $loc->location_id,
            [['product_id' => (int) $p->product_id, 'quantity' => 5, 'picked' => 0]],
            (string) ($p->barcode ?: $p->product_code),
        );

        $this->assertSame(\App\Support\ScanResult::FIFO_VIOLATION, $result->status);
    }

    /** A partial hold still leaves free stock, and free stock is still pickable. */
    public function test_picking_batchless_stock_is_allowed_when_the_hold_is_partial(): void
    {
        ['location' => $loc, 'product' => $p, 'incident' => $incident] = $this->makeScenario(10);

        InventoryBatch::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->delete();

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => null, 'quantity' => 3,
        ]]);

        $result = app(\App\Services\FifoPickingService::class)->validateScan(
            (int) $loc->location_id,
            [['product_id' => (int) $p->product_id, 'quantity' => 5, 'picked' => 0]],
            (string) ($p->barcode ?: $p->product_code),
        );

        $this->assertSame(\App\Support\ScanResult::ACCEPTED, $result->status);
    }

    /** No shortfall, no noise: a clean confirmation leaves the note alone. */
    public function test_a_clean_confirmation_records_no_shortfall(): void
    {
        ['product' => $p, 'incident' => $incident] = $this->makeScenario(100);

        $items = app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => null, 'quantity' => 10,
        ]]);

        app(IncidentItemService::class)->setOutcome($items[0], IncidentItem::OUTCOME_SCRAP, null);
        app(IncidentItemService::class)->applyOutcomes($incident, $this->actorId());

        $this->assertNull($incident->fresh()->resolution_note);
    }
}
