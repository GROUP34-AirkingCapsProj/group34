<?php

namespace Tests\Feature\Holds;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\StockHold;
use App\Services\StockHoldService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockHoldServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function seedStock(int $onHand = 100): array
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
        ]);

        $incident = \App\Models\IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'standalone',
            'location_id'     => $location->location_id,
            'product_id'      => $product->product_id,
            'batch_id'        => $batch->batch_id,
            'reason'          => 'defective',
            'quantity'        => 10,
            'narrative'       => 'test',
            'reported_by'     => \App\Models\User::query()->firstOrFail()->getKey(),
            'status_id'       => \App\Models\IncidentReport::statusId('Open'),
        ]);

        return compact('inventory', 'batch', 'incident');
    }

    /** The core guarantee: nothing vanishes, it moves. */
    public function test_holding_stock_preserves_physical_total(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->seedStock(100);

        app(StockHoldService::class)->hold($incident, StockHold::CONDITION_DEFECTIVE, 10, 1);

        $inv->refresh();
        $batch->refresh();
        $held = StockHold::where('incident_id', $incident->incident_id)->sum('quantity');

        $this->assertSame(90, (int) $inv->quantity_on_hand);
        $this->assertSame(90, (int) $batch->quantity_remaining);
        $this->assertSame(10, (int) $held);
        $this->assertSame(100, (int) $inv->quantity_on_hand + (int) $held);
    }

    public function test_hold_records_condition_batch_and_incident(): void
    {
        ['batch' => $batch, 'incident' => $incident] = $this->seedStock(50);

        $hold = app(StockHoldService::class)->hold($incident, StockHold::CONDITION_RETURNED, 5, 1);

        $this->assertSame(StockHold::CONDITION_RETURNED, $hold->condition);
        $this->assertSame(StockHold::STATUS_HELD, $hold->status);
        $this->assertSame((int) $batch->batch_id, (int) $hold->batch_id);
        $this->assertSame((int) $incident->incident_id, (int) $hold->incident_id);
        $this->assertNull($hold->disposition);
    }

    /** A hold can never exceed what the batch actually holds. */
    public function test_hold_is_clamped_to_available_stock(): void
    {
        ['batch' => $batch, 'incident' => $incident] = $this->seedStock(3);

        $hold = app(StockHoldService::class)->hold($incident, StockHold::CONDITION_MISSING, 999, 1);

        $this->assertSame(3, (int) $hold->quantity);
        $this->assertSame(0, (int) $batch->refresh()->quantity_remaining);
    }

    public function test_restocked_disposition_returns_the_quantity(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 10, 1);

        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);

        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    public function test_scrapped_disposition_does_not_return_the_quantity(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_DEFECTIVE, 10, 1);

        $svc->dispose($hold, StockHold::DISPOSITION_SCRAPPED, null, 1);

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    /** Disposing as `defective` opens a fresh defective hold for the same quantity. */
    public function test_returned_disposed_as_defective_opens_a_defective_hold(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 10, 1);

        $svc->dispose($hold, StockHold::DISPOSITION_DEFECTIVE, null, 1);

        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_DEFECTIVE,
            'status'      => StockHold::STATUS_HELD,
            'quantity'    => 10,
        ]);
    }

    public function test_a_hold_cannot_be_disposed_twice(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 10, 1);
        $svc->dispose($hold, StockHold::DISPOSITION_SCRAPPED, null, 1);

        $this->expectException(\RuntimeException::class);
        $svc->dispose($hold->refresh(), StockHold::DISPOSITION_SCRAPPED, null, 1);
    }

    /** No one-click path puts a known-defective unit back into picking. */
    public function test_a_defective_hold_rejects_restocked(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_DEFECTIVE, 10, 1);

        $this->expectException(\InvalidArgumentException::class);
        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);
    }

    public function test_a_missing_hold_allows_no_disposition_at_all(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_MISSING, 10, 1);

        $this->expectException(\InvalidArgumentException::class);
        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);
    }

    /**
     * A scan must never say "In Stock" about a unit sitting in the defective
     * pile. The serial's own status has to follow the hold, and come back.
     */
    public function test_serial_status_follows_the_hold_and_returns_on_restock(): void
    {
        ['incident' => $incident] = $this->seedStock(100);

        $serial = \App\Models\ItemSerial::create([
            'product_id'    => $incident->product_id,
            'location_id'   => $incident->location_id,
            'serial_number' => 'SN-TEST-' . uniqid(),
            'status_id'     => StockHold::inventoryStatusId('In Stock'),
        ]);
        $incident->update(['serial_id' => $serial->serial_id, 'quantity' => 1]);

        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 1, 1);

        $this->assertSame(
            StockHold::inventoryStatusId('Returned'),
            (int) $serial->refresh()->status_id,
            'a held serial must not still read In Stock'
        );

        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);

        $this->assertSame(
            StockHold::inventoryStatusId('In Stock'),
            (int) $serial->refresh()->status_id
        );
    }

    /**
     * The 128 installation materials carry no serials at all, so a hold on
     * them is purely a quantity — serial_id stays null and nothing tries to
     * stamp a status.
     */
    public function test_non_serialized_stock_holds_by_quantity_alone(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $incident->update(['serial_id' => null]);

        $hold = app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_DEFECTIVE, 7, 1);

        $this->assertNull($hold->serial_id);
        $this->assertSame(7, (int) $hold->quantity);
    }
}
