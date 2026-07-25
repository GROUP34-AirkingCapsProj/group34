<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Support\IssuanceStatus;

class IssuancePickEnforcementTest extends SetTestCase
{
    private function confirmedOrder($loc, array $lines): Issuance
    {
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::pending(),
        ]);

        foreach ($lines as [$product, $qty]) {
            $issuance->details()->create([
                'product_id'         => $product->product_id,
                'quantity_requested' => $qty,
                'quantity_issued'    => 0,
                'unit_price'         => (float) $product->unit_price,
                'subtotal'           => $qty * (float) $product->unit_price,
            ]);
        }

        return $issuance->fresh('details');
    }

    public function test_pick_plan_lists_steps_oldest_first(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 20);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $issuance = $this->confirmedOrder($loc, [[$p, 6]]);

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson("/api/issuances/{$issuance->issuance_id}/pick-plan");

        $res->assertOk();
        $this->assertSame('Zone 1', $res->json('data.0.suggestions.0.zone_label'));
        $this->assertSame(6, (int) $res->json('data.0.needed'));
    }

    public function test_scanning_a_product_not_on_the_order_is_rejected(): void
    {
        $loc     = $this->makeLocation();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');
        $onList  = $this->makeConsumable();
        $offList = $this->makeConsumable(barcode: 'BC-OFFLIST');
        $this->setStock($loc->location_id, $onList->product_id, 10);
        $this->setStock($loc->location_id, $offList->product_id, 10);
        $this->makeBatch($offList, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $issuance = $this->confirmedOrder($loc, [[$onList, 2]]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => 'BC-OFFLIST',
        ]);

        $res->assertStatus(422);
        $this->assertSame('WRONG_PRODUCT', $res->json('scan_result.status'));
        $this->assertFalse($res->json('scan_result.overridable'));
        $this->assertSame(0, (int) $issuance->fresh('details')->details->sum('quantity_issued'));
    }

    public function test_scanning_out_of_fifo_order_is_rejected_as_overridable(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(barcode: 'BC-ENFORCE');
        $this->setStock($loc->location_id, $p->product_id, 20);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $issuance = $this->confirmedOrder($loc, [[$p, 2]]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id'    => $issuance->issuance_id,
            'barcode'        => 'BC-ENFORCE',
            'confirmed_zone' => 'Zone 2',
        ]);

        $res->assertStatus(422);
        $this->assertSame('FIFO_VIOLATION', $res->json('scan_result.status'));
        $this->assertTrue($res->json('scan_result.overridable'));
        $this->assertSame('Zone 1', $res->json('scan_result.expected_zone_label'));
    }

    public function test_scanning_the_oldest_zone_still_commits_the_line(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(barcode: 'BC-COMMIT');
        $this->setStock($loc->location_id, $p->product_id, 20);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);

        $issuance = $this->confirmedOrder($loc, [[$p, 2]]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id'    => $issuance->issuance_id,
            'barcode'        => 'BC-COMMIT',
            'quantity'       => 1,
            'confirmed_zone' => 'Zone 1',
        ]);

        $res->assertOk();
        $this->assertGreaterThan(0, (int) $issuance->fresh('details')->details->sum('quantity_issued'));
    }

    public function test_over_pick_is_enforced_across_separate_scan_rows(): void
    {
        // Proves the per-product aggregation: the commit creates a fresh issued
        // detail each scan, so picked must be summed across rows or over-pick
        // never fires. Line quantity 1; scan it once (ok), then again (blocked).
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(barcode: 'BC-OVERPICK');
        $this->setStock($loc->location_id, $p->product_id, 20);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now()->subDays(3), 'zone_label' => 'Zone 1']);

        $issuance = $this->confirmedOrder($loc, [[$p, 1]]);
        $url = "/api/issuance-scan/scan";

        $this->actingAs($user, 'sanctum')->postJson($url, [
            'issuance_id' => $issuance->issuance_id, 'barcode' => 'BC-OVERPICK',
            'quantity' => 1, 'confirmed_zone' => 'Zone 1',
        ])->assertOk();

        $res = $this->actingAs($user, 'sanctum')->postJson($url, [
            'issuance_id' => $issuance->issuance_id, 'barcode' => 'BC-OVERPICK',
            'quantity' => 1, 'confirmed_zone' => 'Zone 1',
        ]);
        $res->assertStatus(422);
        $this->assertSame('OVER_PICK', $res->json('scan_result.status'));
    }
}
