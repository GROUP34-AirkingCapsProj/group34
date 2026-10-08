<?php

namespace Tests\Feature\Warranty;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesWarrantyTestData;
use Tests\TestCase;

/**
 * The claim list feeds the admin Transaction ledger, which shows every stock
 * movement — receiving, issuance, transfer, adjustment, incident, warranty —
 * side by side with the location it happened at.
 *
 * The index eager-loaded customer, product, status and warranty but not
 * location, so warranty rows were the only ones in that ledger with no
 * location: `location_id` was serialized, the name was not, and the column
 * read "N/A" for every claim.
 */
class WarrantyClaimListPayloadTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesWarrantyTestData;

    public function test_claim_list_carries_the_location_it_happened_at(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        app(\App\Services\WarrantyClaimService::class)->initClaim([
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $appliance['location']->location_id,
        ]);

        $this->getJson('/api/warranty-claims')
            ->assertOk()
            ->assertJsonPath('data.0.location.location_id', $appliance['location']->location_id)
            ->assertJsonPath('data.0.location.location_name', $appliance['location']->location_name);
    }
}
