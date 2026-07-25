<?php

namespace Tests\Feature\Sets;

use App\Services\FifoPickingService;

class FifoSuggestTest extends SetTestCase
{
    public function test_consumable_suggestions_walk_batches_oldest_first(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now()->subDays(10), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now()->subDays(2),  'zone_label' => 'Zone 2']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 6],
        ]);

        $steps = $out[0]['suggestions'];
        $this->assertCount(2, $steps);
        $this->assertSame('Zone 1', $steps[0]['zone_label']);
        $this->assertSame(4, $steps[0]['quantity']);
        $this->assertSame('Zone 2', $steps[1]['zone_label']);
        $this->assertSame(2, $steps[1]['quantity']);
    }

    public function test_appliance_suggestions_list_serials_from_the_oldest_batch_first(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();

        $oldBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);
        $newBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDay(),    'zone_label' => 'Zone 2']);

        $this->makeSerial($p, $loc, 'SN-NEW', $newBatch->receiving_id);
        $this->makeSerial($p, $loc, 'SN-OLD', $oldBatch->receiving_id);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 1],
        ]);

        $steps = $out[0]['suggestions'];
        $this->assertSame('SN-OLD', $steps[0]['serial_number']);
        $this->assertSame('Zone 1', $steps[0]['zone_label']);
    }

    public function test_suggestions_stop_once_the_requested_quantity_is_covered(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $this->makeBatch($p, $loc, ['quantity_remaining' => 50, 'received_at' => now()->subDays(5), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 50, 'received_at' => now()->subDay(),   'zone_label' => 'Zone 2']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 3],
        ]);

        $this->assertCount(1, $out[0]['suggestions']);
        $this->assertSame(3, $out[0]['suggestions'][0]['quantity']);
    }

    public function test_a_product_with_no_stock_yields_no_suggestions(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 2],
        ]);

        $this->assertSame([], $out[0]['suggestions']);
        $this->assertSame(2, $out[0]['needed']);
    }

    public function test_empty_batches_are_skipped(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $this->makeBatch($p, $loc, ['quantity_remaining' => 0, 'received_at' => now()->subDays(20), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(2),  'zone_label' => 'Zone 2']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 2],
        ]);

        $this->assertCount(1, $out[0]['suggestions']);
        $this->assertSame('Zone 2', $out[0]['suggestions'][0]['zone_label']);
    }

    public function test_requesting_more_than_total_stock_returns_only_what_is_available(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $this->makeBatch($p, $loc, ['quantity_remaining' => 3, 'received_at' => now()->subDays(5), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 2, 'received_at' => now()->subDay(),   'zone_label' => 'Zone 2']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 50],
        ]);

        $this->assertSame(50, $out[0]['needed']);
        $steps = $out[0]['suggestions'];
        $this->assertCount(2, $steps);
        $total = array_sum(array_column($steps, 'quantity'));
        $this->assertSame(5, $total);
    }

    public function test_multiple_lines_each_get_their_own_suggestions_block_in_order(): void
    {
        $loc = $this->makeLocation();
        $p1  = $this->makeConsumable();
        $p2  = $this->makeConsumable();

        $this->makeBatch($p1, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(3), 'zone_label' => 'Zone A']);
        $this->makeBatch($p2, $loc, ['quantity_remaining' => 7, 'received_at' => now()->subDays(3), 'zone_label' => 'Zone B']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p1->product_id, 'quantity' => 2],
            ['product_id' => $p2->product_id, 'quantity' => 4],
        ]);

        $this->assertCount(2, $out);
        $this->assertSame($p1->product_id, $out[0]['product_id']);
        $this->assertSame('Zone A', $out[0]['suggestions'][0]['zone_label']);
        $this->assertSame(2, $out[0]['suggestions'][0]['quantity']);

        $this->assertSame($p2->product_id, $out[1]['product_id']);
        $this->assertSame('Zone B', $out[1]['suggestions'][0]['zone_label']);
        $this->assertSame(4, $out[1]['suggestions'][0]['quantity']);
    }

    /**
     * Serial and consumable steps must format received_at identically, so a
     * consumer can compare the field across both without hitting a string-shape
     * mismatch. Serial steps read a raw joined column; consumable steps go
     * through the Carbon cast — both must land on 'Y-m-d H:i:s'.
     */
    public function test_received_at_has_the_same_shape_for_serials_and_consumables(): void
    {
        $loc  = $this->makeLocation();
        $when = now()->subDays(7)->startOfMinute();

        $consumable = $this->makeConsumable();
        $this->makeBatch($consumable, $loc, ['quantity_remaining' => 3, 'received_at' => $when, 'zone_label' => 'Zone 1']);

        $appliance = $this->makeAppliance();
        $batch     = $this->makeBatch($appliance, $loc, ['quantity_remaining' => 1, 'received_at' => $when, 'zone_label' => 'Zone 1']);
        $this->makeSerial($appliance, $loc, 'SN-FMT', $batch->receiving_id);

        $svc = app(FifoPickingService::class);
        $consumableStep = $svc->suggest($loc->location_id, [['product_id' => $consumable->product_id, 'quantity' => 1]])[0]['suggestions'][0];
        $serialStep     = $svc->suggest($loc->location_id, [['product_id' => $appliance->product_id,  'quantity' => 1]])[0]['suggestions'][0];

        $expected = $when->toDateTimeString();
        $this->assertSame($expected, $consumableStep['received_at']);
        $this->assertSame($expected, $serialStep['received_at']);
    }
}
