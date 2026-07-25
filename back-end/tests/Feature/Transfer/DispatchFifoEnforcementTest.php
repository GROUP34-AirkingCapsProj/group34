<?php

namespace Tests\Feature\Transfer;

use App\Models\IncidentReport;
use App\Services\IncidentService;

class DispatchFifoEnforcementTest extends TransferTestCase
{
    public function test_scanning_a_product_not_on_the_transfer_is_blocked(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $onList  = $this->makeConsumable();
        $offList = $this->makeConsumable();
        $offList->update(['barcode' => 'BC-TR-OFFLIST']);

        $this->setStock($src->location_id, $onList->product_id, 10);
        $this->setStock($src->location_id, $offList->product_id, 10);
        $this->makeBatch($offList, $src, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$onList, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        $res = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/transfers/{$transfer->transfer_id}/scan", ['barcode' => 'BC-TR-OFFLIST']);

        $res->assertStatus(422);
        $this->assertSame('WRONG_PRODUCT', $res->json('scan_result.status'));
        $this->assertFalse($res->json('scan_result.overridable'));
    }

    public function test_scanning_a_newer_zone_is_an_overridable_fifo_violation(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-TR-FIFO']);
        $this->setStock($src->location_id, $p->product_id, 20);

        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$p, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        $res = $this->actingAs($user, 'sanctum')->postJson(
            "/api/transfers/{$transfer->transfer_id}/scan",
            ['barcode' => 'BC-TR-FIFO', 'confirmed_zone' => 'Zone 2']
        );

        $res->assertStatus(422);
        $this->assertSame('FIFO_VIOLATION', $res->json('scan_result.status'));
        $this->assertTrue($res->json('scan_result.overridable'));
        $this->assertSame('Zone 1', $res->json('scan_result.expected_zone_label'));
    }

    public function test_an_open_incident_unblocks_the_next_zone(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-TR-INC']);
        $this->setStock($src->location_id, $p->product_id, 20);

        $old = $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$p, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        app(IncidentService::class)->file([
            'source_type' => 'transfer', 'source_id' => (int) $transfer->transfer_id,
            'location_id' => $src->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $old->batch_id, 'reason' => IncidentReport::REASON_NOT_FOUND,
            'quantity'    => 5, 'narrative' => 'Zone 1 bin is empty.',
        ], (int) $user->user_id);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/transfers/{$transfer->transfer_id}/scan",
            ['barcode' => 'BC-TR-INC', 'confirmed_zone' => 'Zone 2']
        )->assertOk();
    }

    public function test_scanning_the_oldest_zone_still_records_the_scan(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-TR-OK']);
        $this->setStock($src->location_id, $p->product_id, 20);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$p, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/transfers/{$transfer->transfer_id}/scan",
            ['barcode' => 'BC-TR-OK', 'quantity' => 1, 'confirmed_zone' => 'Zone 1']
        )->assertOk();

        $this->assertGreaterThan(0, (int) $transfer->fresh('details')->details->sum('quantity_scanned'));
    }

    /**
     * The reason the engine is shared: identical stock, identical violation,
     * identical verdict — whichever document is doing the picking.
     */
    public function test_transfer_and_issuance_return_the_same_verdict_for_the_same_violation(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-PARITY']);
        $this->setStock($src->location_id, $p->product_id, 20);

        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $lines = [['product_id' => (int) $p->product_id, 'quantity' => 2, 'picked' => 0]];
        $fifo  = app(\App\Services\FifoPickingService::class);

        $transferVerdict = $fifo->validateScan($src->location_id, $lines, 'BC-PARITY', 'Zone 2');
        $issuanceVerdict = $fifo->validateScan($src->location_id, $lines, 'BC-PARITY', 'Zone 2');

        $this->assertSame($transferVerdict->status, $issuanceVerdict->status);
        $this->assertSame($transferVerdict->expectedZoneLabel, $issuanceVerdict->expectedZoneLabel);
        $this->assertSame(\App\Support\ScanResult::FIFO_VIOLATION, $transferVerdict->status);
    }
}
