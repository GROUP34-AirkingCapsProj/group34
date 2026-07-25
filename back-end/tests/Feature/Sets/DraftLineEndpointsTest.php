<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;

class DraftLineEndpointsTest extends SetTestCase
{
    private function draftAt($loc): Issuance
    {
        return Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);
    }

    public function test_init_session_now_creates_a_draft(): void
    {
        $loc      = $this->makeLocation();
        $user     = $this->makeUserAt($loc, 'warehouse_personnel');
        $customer = $this->makeCustomer();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/init', [
            'customer_id'   => $customer->customer_id,
            'issuance_type' => 'individual',
            'location_id'   => $loc->location_id,
        ]);

        $res->assertCreated();
        $issuance = Issuance::find($res->json('issuance_id'));
        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->status_id);
    }

    public function test_scanning_a_draft_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(barcode: 'BC-DRAFT-GATE');
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => 'BC-DRAFT-GATE',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('confirm', strtolower($res->json('reason') ?? $res->json('message') ?? ''));
    }

    public function test_adding_a_set_through_the_endpoint_expands_lines(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $a    = $this->makeConsumable(price: 100);

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 2]);

        $issuance = $this->draftAt($loc);

        $res = $this->actingAs($user, 'sanctum')->postJson(
            "/api/issuances/{$issuance->issuance_id}/lines/set",
            ['set_id' => $set->set_id, 'set_quantity' => 3]
        );

        $res->assertOk();
        $this->assertSame(6, (int) $res->json('data.lines.0.quantity_requested'));
    }

    public function test_adding_a_custom_line_through_the_endpoint(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(price: 75);

        $issuance = $this->draftAt($loc);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/issuances/{$issuance->issuance_id}/lines/custom",
            ['product_id' => $p->product_id, 'quantity' => 3]
        )->assertOk();

        $detail = $issuance->fresh('details')->details->first();
        $this->assertNull($detail->issuance_set_id);
        $this->assertSame(3, (int) $detail->quantity_requested);
    }

    public function test_updating_and_removing_lines_through_the_endpoints(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $a    = $this->makeConsumable(price: 10);
        $b    = $this->makeConsumable(price: 20);
        $issuance = $this->draftAt($loc);

        $svc = app(\App\Services\IssuanceOrderService::class);
        $d1  = $svc->addCustomLine($issuance, $a->product_id, 2);
        $d2  = $svc->addCustomLine($issuance, $b->product_id, 1);

        $this->actingAs($user, 'sanctum')->putJson(
            "/api/issuances/{$issuance->issuance_id}/lines/{$d1->issuance_detail_id}",
            ['quantity' => 5]
        )->assertOk();
        $this->assertSame(5, (int) $d1->fresh()->quantity_requested);

        $this->actingAs($user, 'sanctum')->deleteJson(
            "/api/issuances/{$issuance->issuance_id}/lines/{$d2->issuance_detail_id}"
        )->assertOk();
        $this->assertSame(1, $issuance->fresh('details')->details->count());
    }

    public function test_line_endpoints_refuse_a_confirmed_order(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();

        $issuance = $this->draftAt($loc);
        $issuance->update(['status_id' => IssuanceStatus::pending()]);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/issuances/{$issuance->issuance_id}/lines/custom",
            ['product_id' => $p->product_id, 'quantity' => 1]
        )->assertStatus(422);
    }

    public function test_an_auditor_cannot_build_draft_lines(): void
    {
        $loc     = $this->makeLocation();
        $auditor = $this->makeUserAt($loc, 'auditor');
        $p       = $this->makeConsumable();
        $issuance = $this->draftAt($loc);

        $this->actingAs($auditor, 'sanctum')->postJson(
            "/api/issuances/{$issuance->issuance_id}/lines/custom",
            ['product_id' => $p->product_id, 'quantity' => 1]
        )->assertForbidden();
    }
}
