<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesWarrantyTestData;
use Tests\TestCase;

class WarrantyClaimTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesWarrantyTestData;

    public function test_repair_resolves_consumable_by_item_serial_barcode(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 20);

        // Physical scanned barcode lives on an item_serial row, NOT on products.barcode
        $code = 'PARTBC-' . strtoupper(uniqid());
        \App\Models\ItemSerial::create([
            'product_id'    => $part->product_id,
            'location_id'   => $locationId,
            'serial_number' => $code,
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $locationId,
        ]);

        // A scan FULFILS an order line; it no longer creates one. The part has to
        // be on the warranty order before anyone can scan it against the claim.
        $this->service()->addCustomOrderLine($claim, (int) $part->product_id, 2);

        $detail = $this->service()->addItem($claim, ['barcode' => $code, 'quantity' => 2]);

        $this->assertSame((int) $part->product_id, (int) $detail->product_id);
        $this->assertNull($detail->serial_number, 'Consumable repair part should not store a serial.');
        $this->assertSame(2, (int) $detail->quantity_in_pieces);
    }

    public function test_harness_can_build_warranty_fixture(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $this->assertSame(33, $appliance['warranty']->status_id);
        $this->assertDatabaseHas('item_serial', [
            'serial_number' => $appliance['serial'],
            'status_id'     => 26,
        ]);
    }

    public function test_claim_models_and_relations_resolve(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $claim = \App\Models\WarrantyClaim::create([
            'claim_number' => 'WC-TEST-' . uniqid(),
            'warranty_id'  => $appliance['warranty']->warranty_id,
            'customer_id'  => $customer->customer_id,
            'product_id'   => $appliance['product']->product_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
            'status_id'    => \App\Models\StatusLookup::where('status_category','warranty_claim')->where('status_name','Pending')->value('status_id'),
            'created_by'   => \Illuminate\Support\Facades\Auth::id(),
        ]);

        $claim->details()->create([
            'product_id'         => $appliance['product']->product_id,
            'quantity_issued'    => 1,
            'quantity_in_pieces' => 1,
            'unit_cost'          => 10,
            'line_cost'          => 10,
        ]);

        $this->assertCount(1, $claim->fresh()->details);
        $this->assertSame($customer->customer_id, $claim->customer->customer_id);
        $this->assertSame($appliance['warranty']->warranty_id, $claim->warranty->warranty_id);
        $this->assertCount(1, $customer->fresh()->warrantyClaims);
    }

    private function service(): \App\Services\WarrantyClaimService
    {
        return app(\App\Services\WarrantyClaimService::class);
    }

    public function test_init_claim_succeeds_for_active_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'notes'        => 'Not cooling',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->assertSame('repair', $claim->claim_type);
        $this->assertSame($appliance['warranty']->warranty_id, $claim->warranty_id);
        $this->assertStringStartsWith('WC-', $claim->claim_number);
        $pending = \App\Models\StatusLookup::where('status_category','warranty_claim')->where('status_name','Pending')->value('status_id');
        $this->assertSame((int) $pending, (int) $claim->status_id);
    }

    public function test_http_init_derives_customer_from_issued_serial(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $response = $this->postJson('/api/warranty-claims/init', [
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $appliance['location']->location_id,
        ])->assertStatus(201);

        $response->assertJsonPath('data.customer.customer_id', $customer->customer_id);
    }

    public function test_manual_serial_entry_accepts_common_formatting_differences(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $manuallyTyped = strtolower(str_replace('-', ' ', $appliance['serial']));

        $this->postJson('/api/warranty-claims/init', [
            'serial_number' => $manuallyTyped,
            'claim_type'    => 'repair',
            'location_id'   => $appliance['location']->location_id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.serial_number', $appliance['serial']);
    }

    public function test_init_claim_rejects_expired_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer, [
            'warranty_end_date' => \Illuminate\Support\Carbon::today()->subDay()->toDateString(),
            'status_id'         => 34,
        ]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $appliance['location']->location_id,
        ]);
    }

    public function test_init_claim_rejects_already_claimed_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer, ['status_id' => 35]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);
    }

    public function test_init_claim_rejects_customer_mismatch(): void
    {
        $this->actingUser();
        $owner    = $this->makeCustomer();
        $other    = $this->makeCustomer(['customer_name' => 'Someone Else']);
        $appliance = $this->makeAppliance($owner);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->initClaim([
            'customer_id'  => $other->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);
    }

    public function test_repair_addItem_deducts_consumable_stock(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 100);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $locationId,
        ]);

        $this->service()->addCustomOrderLine($claim, (int) $part->product_id, 3);

        $detail = $this->service()->addItem($claim, [
            'barcode'  => $part->barcode ?: $part->product_code,
            'quantity' => 3,
        ]);

        $this->assertSame(3, (int) $detail->quantity_in_pieces);
        $this->assertNull($detail->serial_number);
        $this->assertDatabaseHas('inventory', [
            'product_id'       => $part->product_id,
            'location_id'      => $locationId,
            'quantity_on_hand' => 97,
        ]);
    }

    public function test_replacement_addItem_consumes_serial_unit(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $replacement = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $locationId,
        ]);

        $this->service()->addCustomOrderLine($claim, (int) $replacement['product']->product_id, 1);

        $detail = $this->service()->addItem($claim, [
            'barcode' => $replacement['serial'],
        ]);

        $this->assertSame($replacement['serial'], $detail->serial_number);
        $this->assertDatabaseMissing('item_serial', [
            'serial_number' => $replacement['serial'],
            'status_id'     => 26,
        ]);
    }

    public function test_repair_rejects_serialized_item(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $replacement = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->addItem($claim, ['barcode' => $replacement['serial']]);
    }

    public function test_replacement_allows_only_one_unit(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $r1 = $this->makeAppliance($customer);
        $r2 = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->service()->addCustomOrderLine($claim, (int) $r1['product']->product_id, 1);
        $this->service()->addItem($claim, ['barcode' => $r1['serial']]);

        // The one-unit rule now lives on the ORDER, not on the scan: a
        // replacement order cannot be extended past a single unit, so the second
        // unit is refused before anybody gets to scan it.
        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->addCustomOrderLine($claim->fresh(), (int) $r2['product']->product_id, 1);
    }

    public function test_remove_repair_part_restores_stock(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 100);

        $claim  = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $locationId,
        ]);
        $this->service()->addCustomOrderLine($claim, (int) $part->product_id, 4);
        $detail = $this->service()->addItem($claim, ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 4]);

        $this->service()->removeItem($claim->fresh(), $detail->claim_detail_id);

        $this->assertDatabaseHas('inventory', [
            'product_id' => $part->product_id, 'location_id' => $locationId, 'quantity_on_hand' => 100,
        ]);
        // Reversing a scan un-fulfils the ORDER LINE rather than deleting it —
        // the part is still on the warranty order, just no longer issued, so it
        // can be re-scanned without re-ordering it.
        $this->assertDatabaseHas('warranty_claim_details', [
            'claim_detail_id'    => $detail->claim_detail_id,
            'quantity_requested' => 4,
            'quantity_issued'    => 0,
            'quantity_in_pieces' => 0,
        ]);
    }

    public function test_remove_replacement_unit_restores_serial(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $replacement = $this->makeAppliance($customer);

        $claim  = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $appliance['location']->location_id,
        ]);
        $this->service()->addCustomOrderLine($claim, (int) $replacement['product']->product_id, 1);
        $detail = $this->service()->addItem($claim, ['barcode' => $replacement['serial']]);

        $this->service()->removeItem($claim->fresh(), $detail->claim_detail_id);

        $this->assertDatabaseHas('item_serial', [
            'serial_number' => $replacement['serial'], 'status_id' => 26,
        ]);
    }

    public function test_complete_repair_keeps_original_warranty_active_no_new_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 50);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $locationId,
        ]);
        $this->service()->addCustomOrderLine($claim, (int) $part->product_id, 2);
        $this->service()->addItem($claim, ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 2]);

        $before = \App\Models\WarrantyRecord::count();
        $done = $this->service()->completeClaim($claim->fresh());

        $this->assertSame($this->service()->claimStatusId('Completed'), (int) $done->status_id);
        $this->assertSame(33, (int) $appliance['warranty']->fresh()->status_id);
        $this->assertSame($before, \App\Models\WarrantyRecord::count(), 'Repair must not create a warranty record.');
        $this->assertDatabaseHas('warranty_claim_returns', [
            'claim_id' => $claim->claim_id,
            'holding_status' => 'returned_to_customer',
            'release_disposition' => 'repaired_return',
        ]);
    }

    public function test_complete_replacement_inherits_end_date_and_is_claimable(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $replacement = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;

        $originalEnd = $appliance['warranty']->warranty_end_date->toDateString();

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $locationId,
        ]);
        $this->service()->addCustomOrderLine($claim, (int) $replacement['product']->product_id, 1);
        $this->service()->addItem($claim, ['barcode' => $replacement['serial']]);

        $this->service()->completeClaim($claim->fresh());

        $this->assertSame(35, (int) $appliance['warranty']->fresh()->status_id);

        $new = \App\Models\WarrantyRecord::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($replacement['serial'])])
            ->where('status_id', 33)->latest('warranty_id')->first();
        $this->assertNotNull($new, 'Replacement must create a new active warranty.');
        $this->assertSame($originalEnd, $new->warranty_end_date->toDateString());
    }

    public function test_complete_rejects_empty_claim(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->completeClaim($claim->fresh());
    }

    public function test_http_full_repair_flow(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 30);

        $init = $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'notes'         => 'Compressor noise',
            'location_id'   => $locationId,
        ])->assertStatus(201)->json();

        $claimId = $init['data']['claim_id'] ?? $init['claim_id'];

        $this->postJson("/api/warranty-claims/{$claimId}/order/custom", [
            'product_id'         => $part->product_id,
            'quantity_in_pieces' => 1,
        ])->assertOk();

        $this->postJson("/api/warranty-claims/{$claimId}/item", [
            'barcode'  => $part->barcode ?: $part->product_code,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson("/api/warranty-claims/{$claimId}/complete")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(33, (int) $appliance['warranty']->fresh()->status_id);
    }

    public function test_http_init_rejects_expired_with_422(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer, [
            'warranty_end_date' => \Illuminate\Support\Carbon::today()->subDay()->toDateString(),
            'status_id'         => 34,
        ]);

        $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $appliance['location']->location_id,
        ])->assertStatus(422);
    }

    public function test_customer_history_includes_claims(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 10);

        $init = $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $locationId,
        ])->json();
        $claimId = $init['data']['claim_id'] ?? $init['claim_id'];
        $this->postJson("/api/warranty-claims/{$claimId}/item", ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 1]);
        $this->postJson("/api/warranty-claims/{$claimId}/complete");

        $res = $this->getJson("/api/customers/{$customer->customer_id}/history")->json();
        $data = $res['data'] ?? $res;
        $this->assertArrayHasKey('warranty_claims', $data);
        $this->assertNotEmpty($data['warranty_claims']);
        $this->assertSame('repair', $data['warranty_claims'][0]['claim_type']);
    }

    public function test_issuance_scan_rejects_warranty_type(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();

        $this->postJson('/api/issuance-scan/init', [
            'customer_id'   => $customer->customer_id,
            'issuance_type' => 'warranty',
        ])->assertStatus(422);
    }

    public function test_claim_slip_downloads_csv(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 10);

        $init = $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $locationId,
        ])->json();
        $claimId = $init['data']['claim_id'] ?? $init['claim_id'];
        $this->postJson("/api/warranty-claims/{$claimId}/item", ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 1]);
        $this->postJson("/api/warranty-claims/{$claimId}/complete");

        $res = $this->get("/api/warranty-claims/{$claimId}/slip");
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
    }
}
