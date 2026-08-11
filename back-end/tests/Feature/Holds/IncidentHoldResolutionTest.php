<?php

namespace Tests\Feature\Holds;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\StockHold;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncidentHoldResolutionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A real user id. reviewed_by is a foreign key to users and the seeded ids
     * differ between the dev and test databases, so this cannot be a literal.
     */
    private function reviewerId(): int
    {
        return (int) \App\Models\User::query()->firstOrFail()->getKey();
    }

    private function fileIncident(string $reason, int $onHand = 100, int $qty = 10): array
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        $inventory = Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'status_id'          => StockHold::inventoryStatusId('In Stock'),
            'quantity_on_hand'   => $onHand,
            'available_quantity' => $onHand,
            'reorder_level'      => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_received'  => $onHand,
            'quantity_remaining' => $onHand,
            'received_at'        => now(),
            'zone_label'         => 'ZONE-1',
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'standalone',
            'location_id'     => $location->location_id,
            'product_id'      => $product->product_id,
            'batch_id'        => $batch->batch_id,
            'reason'          => $reason,
            'quantity'        => $qty,
            'narrative'       => 'test',
            'actual_zone'     => 'ZONE-9',
            'expected_zone'   => 'ZONE-1',
            'reported_by'     => \App\Models\User::query()->firstOrFail()->getKey(),
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        return compact('inventory', 'batch', 'incident');
    }

    public function test_missing_deducts_stock_and_opens_a_terminal_hold(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->fileIncident('missing');

        app(IncidentService::class)->resolve($incident, 'confirm', [], $this->reviewerId());

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $hold = StockHold::where('incident_id', $incident->incident_id)->firstOrFail();
        $this->assertSame(StockHold::CONDITION_MISSING, $hold->condition);
        $this->assertSame([], StockHold::ALLOWED_DISPOSITIONS[$hold->condition]);
    }

    public function test_defective_deducts_stock_and_opens_a_defective_hold(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->fileIncident('defective');

        app(IncidentService::class)->resolve($incident, 'confirm', [], $this->reviewerId());

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_DEFECTIVE,
            'status'      => StockHold::STATUS_HELD,
        ]);
    }

    /**
     * REVERSAL: RestockResolution used to increment on approval, putting a
     * customer return straight back into pickable stock with nobody having
     * looked at it. It now holds.
     */
    public function test_a_return_does_not_become_sellable_on_approval(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->fileIncident('return');

        app(IncidentService::class)->resolve($incident, 'confirm', [], $this->reviewerId());

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand, 'a return must not be sellable on approval');
        $this->assertSame(90, (int) $batch->refresh()->quantity_remaining);
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_RETURNED,
        ]);
    }

    /**
     * REVERSAL: RelocateResolution rewrote the batch's zone_label from
     * actual_zone. The system record is CORRECT and the shelf is wrong — the
     * fix is physical. Resolving must change nothing.
     */
    public function test_zone_mismatch_changes_absolutely_nothing(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->fileIncident('zone_mismatch');

        app(IncidentService::class)->resolve($incident, 'confirm', [], $this->reviewerId());

        $this->assertSame('ZONE-1', $batch->refresh()->zone_label, 'the system zone must not be overwritten');
        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->quantity_remaining);
        $this->assertSame(0, StockHold::where('incident_id', $incident->incident_id)->count());
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->refresh()->status_id);
    }
}
