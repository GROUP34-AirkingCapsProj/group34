<?php

namespace Tests\Feature\Procurement;

use App\Events\PurchaseOrderChanged;
use Illuminate\Support\Facades\Event;

class PoVerificationTest extends ProcurementTestCase
{
    public function test_auditor_verifies_po(): void
    {
        $auditor = $this->makeUser('auditor');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify", ['note' => 'Matches PR + supplier quote'])
            ->assertSuccessful();

        $po->refresh();
        $this->assertSame('verified', $po->verification_status);
        $this->assertSame($auditor->user_id, (int) $po->verified_by);
        $this->assertNotNull($po->verified_at);
    }

    public function test_auditor_rejects_po_requires_reason(): void
    {
        $auditor = $this->makeUser('auditor');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", ['reason' => 'Supplier not accredited'])
            ->assertSuccessful();

        $po->refresh();
        $this->assertSame('rejected', $po->verification_status);
        $this->assertSame(7, (int) $po->status_id);
    }

    public function test_cannot_reject_already_approved_po(): void
    {
        $auditor = $this->makeUser('auditor');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 10); // Authorized

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", ['reason' => 'too late'])
            ->assertStatus(422);
    }

    public function test_cannot_verify_po_you_created(): void
    {
        $auditor = $this->makeUser('auditor');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7);
        $po->update(['created_by' => $auditor->user_id]);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify")
            ->assertStatus(422);
    }

    public function test_po_verify_fires_realtime_signal(): void
    {
        Event::fake([PurchaseOrderChanged::class]);
        $auditor = $this->makeUser('auditor');
        $pr = $this->makePr(4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify")
            ->assertSuccessful();

        Event::assertDispatched(PurchaseOrderChanged::class, fn ($e) => $e->type === 'verification_changed');
    }

    public function test_verified_by_cannot_be_set_via_po_create(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $someone = $this->makeUser('admin');
        $pr = $this->makePr(4000, 14);
        $supplierId = (int) \Illuminate\Support\Facades\DB::table('suppliers')
            ->insertGetId(['supplier_name' => 'S' . uniqid(), 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $productId = $this->makeProductForPo();

        $response = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-orders', [
            'supplier_id' => $supplierId,
            'location_id' => $this->locationId,
            'po_number'   => 'PO-' . uniqid(),
            'order_date'  => today()->toDateString(),
            'verified_by' => $someone->user_id, // must be ignored
            'details'     => [[
                'product_id' => $productId, 'quantity_ordered' => 1, 'unit_price' => 100,
            ]],
        ]);

        $response->assertStatus(201);
        $poId = $response->json('data.po_id');
        $this->assertNull(\App\Models\PurchaseOrder::find($poId)->verified_by);
        $this->assertSame('pending', \App\Models\PurchaseOrder::find($poId)->verification_status);
    }

    private function makeProductForPo(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'product_code' => 'TP-' . uniqid(), 'product_name' => 'PO Product',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
    }
}
