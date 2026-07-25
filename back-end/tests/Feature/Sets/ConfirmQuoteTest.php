<?php

namespace Tests\Feature\Sets;

use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\PickReservation;
use App\Services\ReservationService;
use App\Support\IssuanceStatus;

class ConfirmQuoteTest extends SetTestCase
{
    private function draftWithLine($loc, $product, int $qty, float $price = 100): Issuance
    {
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $issuance->details()->create([
            'product_id'         => $product->product_id,
            'quantity_requested' => $qty,
            'quantity_issued'    => 0,
            'unit_price'         => $price,
            'subtotal'           => $qty * $price,
        ]);

        return $issuance;
    }

    public function test_confirming_moves_draft_to_pending_and_writes_totals(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 4);

        $res = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote");

        $res->assertOk();
        $fresh = $issuance->fresh();
        $this->assertSame(IssuanceStatus::pending(), (int) $fresh->status_id);
        $this->assertEqualsWithDelta(400.0, (float) $fresh->subtotal, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $fresh->total_amount, 0.001);
    }

    public function test_confirming_snapshots_cost_price_on_each_line(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 2);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        // A later price change must not rewrite what was already snapshotted.
        $p->update(['cost_price' => 999]);

        $detail = $issuance->fresh('details')->details->first();
        $this->assertEqualsWithDelta(60.0, (float) $detail->cost_price_at_issue, 0.001);
    }

    public function test_confirming_reserves_as_rows_without_touching_inventory_columns(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 5);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        // The hold exists as a row...
        $this->assertSame(5, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->sum('quantity'));

        // ...but NEITHER inventory column moved. A reservation is a row, not a decrement.
        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(20, (int) $inv->available_quantity, 'available_quantity is never mutated by a hold');
        $this->assertSame(20, (int) $inv->quantity_on_hand, 'on-hand does not move until a scan commits');
    }

    public function test_another_order_sees_reduced_derived_availability_after_a_confirm(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 20);

        // First order confirms a hold of 5.
        $first = $this->draftWithLine($loc, $p, 5, 10);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$first->issuance_id}/confirm-quote")->assertOk();

        // A second, still-draft order's quote must now derive only 15 available.
        $second = $this->draftWithLine($loc, $p, 20, 10);
        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$second->issuance_id}/quote");

        $this->assertSame(15, (int) $res->json('data.lines.0.available'));
    }

    public function test_short_line_records_the_shortfall_and_reduces_the_request(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 3);

        $issuance = $this->draftWithLine($loc, $p, 8, 10);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote", ['accept_shortages' => true])
             ->assertOk();

        $detail = $issuance->fresh('details')->details->first();
        $this->assertSame(3, (int) $detail->quantity_requested);
        $this->assertSame(5, (int) $detail->quantity_short);
        $this->assertEqualsWithDelta(30.0, (float) $issuance->fresh()->total_amount, 0.001);
    }

    public function test_shortage_without_acknowledgement_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 1);

        $issuance = $this->draftWithLine($loc, $p, 9);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")
             ->assertStatus(422);

        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
        $this->assertSame(0, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->count());
    }

    public function test_confirming_an_empty_order_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")
             ->assertStatus(422);
    }

    public function test_confirming_twice_is_rejected_and_does_not_double_reserve(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 2);
        $url = "/api/issuances/{$issuance->issuance_id}/confirm-quote";

        $this->actingAs($user, 'sanctum')->postJson($url)->assertOk();
        $this->actingAs($user, 'sanctum')->postJson($url)->assertStatus(422);

        $this->assertSame(2, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->sum('quantity'));
    }

    public function test_two_orders_cannot_reserve_the_same_stock_twice(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 6);

        $first  = $this->draftWithLine($loc, $p, 6);
        $second = $this->draftWithLine($loc, $p, 6);

        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$first->issuance_id}/confirm-quote")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$second->issuance_id}/confirm-quote")->assertStatus(422);
    }

    public function test_releasing_a_hold_frees_the_stock_without_crediting_columns(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftWithLine($loc, $p, 4, 10);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        app(ReservationService::class)->releaseFor('issuance', (int) $issuance->issuance_id);

        // Hold gone...
        $this->assertSame(0, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->sum('quantity'));
        // ...available_quantity never moved in either direction...
        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(10, (int) $inv->available_quantity, 'release credits nothing — the column never moved');

        // ...and derived availability is full again for a fresh order.
        $other = $this->draftWithLine($loc, $p, 10, 10);
        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$other->issuance_id}/quote");
        $this->assertSame(10, (int) $res->json('data.lines.0.available'));
    }

    public function test_confirm_with_pool_fully_held_elsewhere_rejects_and_reserves_nothing(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 5);

        $first = $this->draftWithLine($loc, $p, 5, 10);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$first->issuance_id}/confirm-quote")->assertOk();

        $second = $this->draftWithLine($loc, $p, 5, 10);
        $res = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/issuances/{$second->issuance_id}/confirm-quote", ['accept_shortages' => true]);

        $res->assertStatus(422);
        $this->assertSame(0, (int) PickReservation::active()
            ->forSource('issuance', (int) $second->issuance_id)->count());
    }
}
