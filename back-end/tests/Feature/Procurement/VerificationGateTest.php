<?php

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;

class VerificationGateTest extends ProcurementTestCase
{
    private function makeProduct(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Gate Test Product',
            'product_type' => 'installation_material',
            'unit_price'   => 100,
            'cost_price'   => 80,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');
    }

    public function test_po_cannot_be_authorized_while_unverified(): void
    {
        $admin = $this->makeUser('admin');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7); // pending verification

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 10]);

        $response->assertStatus(422);
        $this->assertStringContainsString('verified', strtolower($response->json('message')));
        $this->assertSame(7, (int) $po->fresh()->status_id);
    }

    public function test_po_cannot_be_completed_directly_while_unverified(): void
    {
        $admin = $this->makeUser('admin');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7); // pending, unverified

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 11]); // Completed — bypass attempt

        $response->assertStatus(422);
        $this->assertStringContainsString('verified', strtolower($response->json('message')));
        $this->assertSame(7, (int) $po->fresh()->status_id);
    }

    public function test_verified_po_can_be_authorized(): void
    {
        $admin = $this->makeUser('admin');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7);
        $po->update(['verification_status' => 'verified']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 10])
            ->assertSuccessful();

        $this->assertSame(10, (int) $po->fresh()->status_id);
    }

    public function test_non_approval_update_of_unverified_po_still_allowed(): void
    {
        $admin = $this->makeUser('admin');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['notes' => 'updated note'])
            ->assertSuccessful();
    }

    public function test_receiving_blocked_against_unverified_po(): void
    {
        $wp = $this->makeUser('warehouse_personnel');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 10); // authorized
        $po->update(['verification_status' => 'pending']); // but not verified

        $productId = $this->makeProduct();
        DB::table('purchase_order_details')->insert([
            'po_id' => $po->po_id, 'product_id' => $productId,
            'quantity_ordered' => 2, 'unit_price' => 2000, 'subtotal' => 4000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($wp, 'sanctum')->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $this->locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => today()->toDateString(),
            'details'          => [[
                'product_id'        => $productId,
                'quantity_amount'   => 2,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('verified', strtolower($response->json('message')));
    }
}
