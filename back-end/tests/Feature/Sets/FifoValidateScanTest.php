<?php

namespace Tests\Feature\Sets;

use App\Services\FifoPickingService;
use App\Support\ScanResult;
use Illuminate\Support\Facades\DB;

class FifoValidateScanTest extends SetTestCase
{
    public function test_scanning_the_oldest_zone_is_accepted(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-FIFO-OK');
        $old = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]],
            'BC-FIFO-OK',
            'Zone 1'
        );

        $this->assertSame(ScanResult::ACCEPTED, $result->status);
        $this->assertSame((int) $old->batch_id, $result->batchId);
    }

    public function test_scanning_a_newer_zone_is_a_fifo_violation(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-FIFO-BAD');
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]],
            'BC-FIFO-BAD',
            'Zone 2'
        );

        $this->assertSame(ScanResult::FIFO_VIOLATION, $result->status);
        $this->assertTrue($result->overridable());
        $this->assertSame('Zone 1', $result->expectedZoneLabel);
    }

    public function test_a_product_not_on_the_list_is_a_hard_block(): void
    {
        $loc     = $this->makeLocation();
        $onList  = $this->makeConsumable();
        $offList = $this->makeConsumable(barcode: 'BC-NOT-LISTED');
        $this->makeBatch($offList, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $onList->product_id, 'quantity' => 1, 'picked' => 0]],
            'BC-NOT-LISTED',
            'Zone 1'
        );

        $this->assertSame(ScanResult::WRONG_PRODUCT, $result->status);
        $this->assertFalse($result->overridable(), 'an incident must never legitimise a wrong product');
    }

    public function test_unknown_barcode_is_a_hard_block(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 1, 'picked' => 0]],
            'NOTHING-' . uniqid(),
            null
        );

        $this->assertSame(ScanResult::UNKNOWN_BARCODE, $result->status);
        $this->assertFalse($result->overridable());
    }

    public function test_scanning_past_the_line_quantity_is_over_pick(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-OVER');
        $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 2, 'picked' => 2]],
            'BC-OVER',
            'Zone 1'
        );

        $this->assertSame(ScanResult::OVER_PICK, $result->status);
        $this->assertFalse($result->overridable());
    }

    public function test_an_excluded_batch_no_longer_blocks_the_next_zone(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-EXCLUDED');
        $old = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]],
            'BC-EXCLUDED',
            'Zone 2',
            null,
            [(int) $old->batch_id]
        );

        $this->assertSame(ScanResult::ACCEPTED, $result->status);
    }

    public function test_a_serial_from_the_newer_batch_is_a_violation(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $oldBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);
        $newBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDay(),    'zone_label' => 'Zone 2']);

        $oldSerial = $this->makeSerial($p, $loc, 'SN-OLD-V', $oldBatch->receiving_id);
        $newSerial = $this->makeSerial($p, $loc, 'SN-NEW-V', $newBatch->receiving_id);
        DB::table('item_serial')->whereIn('serial_id', [$oldSerial->serial_id, $newSerial->serial_id])
          ->update(['status_id' => 26]);

        $svc   = app(FifoPickingService::class);
        $lines = [['product_id' => $p->product_id, 'quantity' => 1, 'picked' => 0]];

        $bad = $svc->validateScan($loc->location_id, $lines, 'SN-NEW-V', null);
        $this->assertSame(ScanResult::FIFO_VIOLATION, $bad->status);
        $this->assertSame('Zone 1', $bad->expectedZoneLabel);

        $good = $svc->validateScan($loc->location_id, $lines, 'SN-OLD-V', null);
        $this->assertSame(ScanResult::ACCEPTED, $good->status);
        $this->assertSame('SN-OLD-V', $good->serialNumber);
    }

    public function test_an_older_batch_appearing_mid_pick_makes_the_next_scan_a_violation(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-MIDPICK');
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(2), 'zone_label' => 'Zone 2']);

        $svc   = app(FifoPickingService::class);
        $lines = [['product_id' => $p->product_id, 'quantity' => 4, 'picked' => 0]];

        // Zone 2 is the only stock, so it is legitimately the oldest.
        $this->assertSame(ScanResult::ACCEPTED, $svc->validateScan($loc->location_id, $lines, 'BC-MIDPICK', 'Zone 2')->status);

        // A transfer lands older stock in Zone 1 while the picker is still walking.
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(20), 'zone_label' => 'Zone 1']);

        $after = $svc->validateScan($loc->location_id, $lines, 'BC-MIDPICK', 'Zone 2');
        $this->assertSame(ScanResult::FIFO_VIOLATION, $after->status);
        $this->assertSame('Zone 1', $after->expectedZoneLabel);
    }

    public function test_a_consumable_scan_with_no_zone_confirmation_is_accepted_against_the_oldest_batch(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-NOZONE');
        $old = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(3), 'zone_label' => null]);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 1, 'picked' => 0]],
            'BC-NOZONE',
            null
        );

        $this->assertSame(ScanResult::ACCEPTED, $result->status);
        $this->assertSame((int) $old->batch_id, $result->batchId);
    }

    public function test_a_confirmed_zone_that_matches_no_batch_is_a_violation(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-BOGUS-ZONE');
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(4), 'zone_label' => 'Zone 1']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 1, 'picked' => 0]],
            'BC-BOGUS-ZONE',
            'Zone 99'
        );

        $this->assertSame(ScanResult::FIFO_VIOLATION, $result->status);
        $this->assertSame('Zone 1', $result->expectedZoneLabel);
    }

    public function test_the_scanned_product_may_be_the_second_line_on_the_order(): void
    {
        $loc = $this->makeLocation();
        $a   = $this->makeConsumable();
        $b   = $this->makeConsumable(barcode: 'BC-SECOND-LINE');
        $this->makeBatch($b, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(3), 'zone_label' => 'Zone 1']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [
                ['product_id' => $a->product_id, 'quantity' => 1, 'picked' => 0],
                ['product_id' => $b->product_id, 'quantity' => 1, 'picked' => 0],
            ],
            'BC-SECOND-LINE',
            'Zone 1'
        );

        $this->assertSame(ScanResult::ACCEPTED, $result->status);
        $this->assertSame((int) $b->product_id, $result->productId);
    }
}
