<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\IncidentItemService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncidentItemServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function actorId(): int
    {
        return (int) \App\Models\User::query()->firstOrFail()->getKey();
    }

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

        $batch = InventoryBatch::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_received' => $onHand, 'quantity_remaining' => $onHand, 'received_at' => now(),
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'batch_id' => $batch->batch_id, 'reason' => 'defective', 'quantity' => 10,
            'narrative' => 'test', 'reported_by' => $this->actorId(),
            'status_id' => IncidentReport::statusId('Open'),
        ]);

        return compact('inventory', 'batch', 'incident', 'product');
    }

    /** THE core guarantee: filing quarantines but does not deduct. */
    public function test_quarantine_does_not_change_the_stock_count(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);

        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand, 'filing must not deduct');
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        $this->assertSame(IncidentItem::STATUS_QUARANTINED,
            IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail()->status);
    }

    /** …but it IS unpickable. */
    public function test_a_quarantined_batch_is_excluded_from_picking(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);

        $this->assertContains(
            (int) $batch->batch_id,
            IncidentItem::quarantinedBatchIds((int) $incident->location_id)
        );
    }

    /** One report, two units, two different endings. */
    public function test_each_item_gets_its_own_outcome(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [
            ['product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 1, 'unit_role' => 'indoor'],
            ['product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 1, 'unit_role' => 'outdoor'],
        ]);

        $items = IncidentItem::where('incident_id', $incident->incident_id)->orderBy('item_id')->get();
        $svc->setOutcome($items[0], IncidentItem::OUTCOME_SCRAP, 'panel cracked');
        $svc->setOutcome($items[1], IncidentItem::OUTCOME_RESTOCK, 'undamaged');

        $svc->applyOutcomes($incident, $this->actorId());

        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $items[0]->refresh()->status);
        $this->assertSame(IncidentItem::STATUS_AVAILABLE, $items[1]->refresh()->status);
    }

    /** Confirmation is where stock finally moves — one restock, one scrap. */
    public function test_applying_outcomes_moves_stock_exactly_once(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);
        $item = IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
        $svc->setOutcome($item, IncidentItem::OUTCOME_SCRAP, 'unsellable');

        $svc->applyOutcomes($incident, $this->actorId());

        // Scrapped stock leaves the building.
        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(90, (int) $batch->refresh()->quantity_remaining);
    }

    public function test_a_restock_outcome_leaves_the_count_untouched(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);
        $item = IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
        $svc->setOutcome($item, IncidentItem::OUTCOME_RESTOCK, 'fine');

        $svc->applyOutcomes($incident, $this->actorId());

        // It was never deducted, so restocking it is a no-op on the numbers —
        // only the item's status changes, and the batch becomes pickable again.
        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        $this->assertNotContains(
            (int) $batch->batch_id,
            IncidentItem::quarantinedBatchIds((int) $incident->location_id)
        );
    }

    public function test_outcomes_cannot_be_applied_twice(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);
        $item = IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
        $svc->setOutcome($item, IncidentItem::OUTCOME_SCRAP, null);
        $svc->applyOutcomes($incident, $this->actorId());

        $this->expectException(\RuntimeException::class);
        $svc->applyOutcomes($incident, $this->actorId());
    }

    public function test_an_item_without_an_outcome_blocks_confirmation(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $svc->applyOutcomes($incident, $this->actorId());
    }
}
