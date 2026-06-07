<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesWarrantyTestData;
use Tests\TestCase;

class WarrantyClaimTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesWarrantyTestData;

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
}
