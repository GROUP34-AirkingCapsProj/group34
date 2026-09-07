<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\PickReservation;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;

class IssuanceSetModelTest extends SetTestCase
{
    public function test_issuance_exposes_its_sets_and_details_link_back(): void
    {
        $loc      = $this->makeLocation();
        $customer = $this->makeCustomer();
        $product  = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $customer->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $issuanceSet = $issuance->sets()->create([
            'set_id'            => $set->set_id,
            'set_quantity'      => 2,
            'set_name_snapshot' => $set->label(),
        ]);

        $detail = $issuance->details()->create([
            'issuance_set_id'    => $issuanceSet->issuance_set_id,
            'product_id'         => $product->product_id,
            'quantity_requested' => 4,
            'quantity_issued'    => 0,
            'unit_price'         => 50,
        ]);

        $this->assertSame(1, $issuance->fresh('sets')->sets->count());
        $this->assertSame(
            (int) $issuanceSet->issuance_set_id,
            (int) $detail->fresh('issuanceSet')->issuanceSet->issuance_set_id
        );
        $this->assertFalse((bool) $issuanceSet->was_modified);
    }

    public function test_issuance_set_product_set_relation_survives_archival(): void
    {
        $loc      = $this->makeLocation();
        $customer = $this->makeCustomer();

        $set = ProductSet::create(['capacity_rating' => '1.5HP', 'variant' => 'inverter']);

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $customer->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $issuanceSet = $issuance->sets()->create([
            'set_id'            => $set->set_id,
            'set_quantity'      => 1,
            'set_name_snapshot' => $set->label(),
        ]);

        $set->delete();

        $this->assertNotNull($issuanceSet->fresh()->productSet);
        $this->assertSame($set->set_id, $issuanceSet->fresh()->productSet->set_id);
    }

    public function test_active_scope_excludes_released_and_expired_reservations_but_includes_live_one(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeInstallationMaterial();

        $released = PickReservation::create([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'product_id'  => $product->product_id,
            'location_id' => $loc->location_id,
            'quantity'    => 5,
            'expires_at'  => now()->addMinutes(10),
            'released_at' => now(),
        ]);

        $expired = PickReservation::create([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'product_id'  => $product->product_id,
            'location_id' => $loc->location_id,
            'quantity'    => 5,
            'expires_at'  => now()->subMinutes(1),
        ]);

        $live = PickReservation::create([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'product_id'  => $product->product_id,
            'location_id' => $loc->location_id,
            'quantity'    => 5,
            'expires_at'  => now()->addMinutes(10),
        ]);

        $activeIds = PickReservation::active()->pluck('reservation_id')->all();

        $this->assertNotContains($released->reservation_id, $activeIds);
        $this->assertNotContains($expired->reservation_id, $activeIds);
        $this->assertContains($live->reservation_id, $activeIds);
    }

    public function test_for_source_scope_does_not_cross_source_types_with_same_numeric_id(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeInstallationMaterial();

        $issuanceReservation = PickReservation::create([
            'source_type' => 'issuance',
            'source_id'   => 42,
            'product_id'  => $product->product_id,
            'location_id' => $loc->location_id,
            'quantity'    => 3,
            'expires_at'  => now()->addMinutes(10),
        ]);

        $transferReservation = PickReservation::create([
            'source_type' => 'transfer',
            'source_id'   => 42,
            'product_id'  => $product->product_id,
            'location_id' => $loc->location_id,
            'quantity'    => 3,
            'expires_at'  => now()->addMinutes(10),
        ]);

        $results = PickReservation::forSource('issuance', 42)->pluck('reservation_id')->all();

        $this->assertContains($issuanceReservation->reservation_id, $results);
        $this->assertNotContains($transferReservation->reservation_id, $results);
    }

    /**
     * ReservationService's primary query is active()->forSource(...), so the two
     * scopes composing correctly is what actually gates stock. Testing them only
     * in isolation would miss a careless orWhere added to either one.
     */
    public function test_active_and_for_source_compose_without_leaking_rows(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeInstallationMaterial();

        $base = [
            'product_id'  => $product->product_id,
            'location_id' => $loc->location_id,
            'quantity'    => 1,
        ];

        $wanted = PickReservation::create($base + [
            'source_type' => 'issuance', 'source_id' => 77,
            'expires_at'  => now()->addMinutes(10),
        ]);
        $expiredSameSource = PickReservation::create($base + [
            'source_type' => 'issuance', 'source_id' => 77,
            'expires_at'  => now()->subMinute(),
        ]);
        $releasedSameSource = PickReservation::create($base + [
            'source_type' => 'issuance', 'source_id' => 77,
            'expires_at'  => now()->addMinutes(10),
            'released_at' => now(),
        ]);
        $liveWrongSource = PickReservation::create($base + [
            'source_type' => 'transfer', 'source_id' => 77,
            'expires_at'  => now()->addMinutes(10),
        ]);

        foreach ([
            PickReservation::active()->forSource('issuance', 77)->pluck('reservation_id')->all(),
            PickReservation::forSource('issuance', 77)->active()->pluck('reservation_id')->all(),
        ] as $results) {
            $this->assertContains($wanted->reservation_id, $results);
            $this->assertNotContains($expiredSameSource->reservation_id, $results);
            $this->assertNotContains($releasedSameSource->reservation_id, $results);
            $this->assertNotContains($liveWrongSource->reservation_id, $results);
        }
    }
}
