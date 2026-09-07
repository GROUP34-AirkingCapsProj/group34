<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\PickReservation;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;

class IssuanceQuoteTest extends SetTestCase
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

    public function test_quote_totals_lines_and_applies_discount(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 50);

        $issuance = $this->draftAt($loc);
        $issuance->update(['discount_type' => 'business', 'discount_percent' => 10]);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 4,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 400,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $res->assertOk();
        $this->assertEqualsWithDelta(400.0, (float) $res->json('data.subtotal'), 0.001);
        $this->assertEqualsWithDelta(40.0,  (float) $res->json('data.discount_amount'), 0.001);
        $this->assertEqualsWithDelta(360.0, (float) $res->json('data.total_amount'), 0.001);
    }

    public function test_quote_divides_package_price_by_pieces_per_package(): void
    {
        $loc = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $product = $this->makeInstallationMaterial(price: 50, cost: 25);
        $product->installationMaterialDetail()->create([
            'pieces_per_package' => 5,
            'package_unit' => 'box',
        ]);
        $this->setStock($loc->location_id, $product->product_id, 20);

        $issuance = $this->draftAt($loc);
        // Simulate an older draft that incorrectly snapshotted the package
        // price. The quote must still normalize it to the current piece price.
        $issuance->details()->create([
            'product_id' => $product->product_id,
            'quantity_requested' => 4,
            'quantity_issued' => 0,
            'unit_price' => 50,
            'subtotal' => 200,
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/issuances/{$issuance->issuance_id}/quote")
            ->assertOk();

        $this->assertEqualsWithDelta(50.0, (float) $res->json('data.lines.0.package_price'), 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $res->json('data.lines.0.unit_price'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $res->json('data.lines.0.line_total'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $res->json('data.subtotal'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $res->json('data.total_amount'), 0.001);
    }

    public function test_short_line_is_flagged_with_available_quantity(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 3);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 8,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 80,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $line = $res->json('data.lines.0');
        $this->assertSame(3, (int) $line['available']);
        $this->assertSame(5, (int) $line['short_by']);
        $this->assertTrue($line['is_short']);
        $this->assertTrue($res->json('data.has_shortages'));
    }

    public function test_another_orders_active_reservation_reduces_availability(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 10);

        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => 999999,
            'product_id'  => $p->product_id, 'location_id' => $loc->location_id,
            'quantity'    => 7, 'expires_at' => now()->addHour(),
        ]);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 5,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 50,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $this->assertSame(3, (int) $res->json('data.lines.0.available'));
        $this->assertSame(2, (int) $res->json('data.lines.0.short_by'));
    }

    public function test_expired_reservation_does_not_reduce_availability(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 10);

        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => 999998,
            'product_id'  => $p->product_id, 'location_id' => $loc->location_id,
            'quantity'    => 7, 'expires_at' => now()->subMinute(),
        ]);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 5,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 50,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $this->assertSame(10, (int) $res->json('data.lines.0.available'));
        $this->assertFalse($res->json('data.lines.0.is_short'));
    }

    public function test_margin_is_hidden_from_a_role_without_view_margin(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeInstallationMaterial(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 200,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $res->assertOk();
        $this->assertNull($res->json('data.total_cost'));
        $this->assertNull($res->json('data.lines.0.cost_price'));
    }

    public function test_margin_is_visible_to_an_inventory_analyst(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 200,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $this->assertEqualsWithDelta(120.0, (float) $res->json('data.total_cost'), 0.001);
        $this->assertEqualsWithDelta(80.0,  (float) $res->json('data.total_margin'), 0.001);
    }

    public function test_quote_groups_lines_under_their_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 100);

        $set      = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $issuance = $this->draftAt($loc);
        $group    = $issuance->sets()->create([
            'set_id' => $set->set_id, 'set_quantity' => 2, 'set_name_snapshot' => 'BOQ',
        ]);
        $issuance->details()->create([
            'issuance_set_id' => $group->issuance_set_id,
            'product_id' => $p->product_id, 'quantity_requested' => 6,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 60,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $groups = $res->json('data.set_groups');
        $this->assertCount(1, $groups);
        $this->assertSame('BOQ', $groups[0]['set_name_snapshot']);
        $this->assertSame(2, (int) $groups[0]['set_quantity']);
        $this->assertEqualsWithDelta(60.0, (float) $groups[0]['group_total'], 0.001);
    }

    public function test_auditor_can_fetch_quote_and_sees_margin(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'auditor');
        $p    = $this->makeInstallationMaterial(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 200,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $res->assertOk();
        $this->assertNotNull($res->json('data.total_cost'));
    }

    public function test_own_orders_active_reservation_does_not_reduce_its_own_availability(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 5,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 50,
        ]);

        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => $issuance->issuance_id,
            'product_id'  => $p->product_id, 'location_id' => $loc->location_id,
            'quantity'    => 7, 'expires_at' => now()->addHour(),
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $this->assertSame(10, (int) $res->json('data.lines.0.available'));
        $this->assertFalse($res->json('data.lines.0.is_short'));
    }

    /**
     * Two lines of the same product share one stock pool. The second must be
     * short of whatever the first already claimed — otherwise both look
     * coverable while together they exceed on-hand, which is the whole point of
     * showing availability before the customer commits.
     */
    public function test_two_lines_of_the_same_product_share_one_pool(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        // A set line and a custom line for the same product, 6 each = 12 > 10.
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 6,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 60,
        ]);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 6,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 60,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");
        $res->assertOk();

        $lines = $res->json('data.lines');
        $this->assertFalse($lines[0]['is_short'], 'first line is fully covered');
        $this->assertSame(0, (int) $lines[0]['short_by']);
        // Pool was 10, first line took 6, so the second is short by 6-4 = 2.
        $this->assertTrue($lines[1]['is_short'], 'second line exceeds the shared pool');
        $this->assertSame(2, (int) $lines[1]['short_by']);
        $this->assertTrue($res->json('data.has_shortages'));
    }

    public function test_quote_still_prices_a_line_whose_product_was_archived(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeInstallationMaterial(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 200,
        ]);

        $p->delete(); // archived after the line was added

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");
        $res->assertOk();

        $line = $res->json('data.lines.0');
        $this->assertNotNull($line['product_name'], 'archived product still names the line');
        $this->assertEqualsWithDelta(200.0, (float) $line['line_total'], 0.001);
        // Margin viewer must still see a real cost, not a zeroed one.
        $this->assertEqualsWithDelta(120.0, (float) $res->json('data.total_cost'), 0.001);
    }

    public function test_hidden_cost_fields_are_present_as_null_not_absent(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel'); // no view_margin
        $p    = $this->makeInstallationMaterial(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 200,
        ]);

        $res  = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");
        $line = $res->json('data.lines.0');

        // The frontend branches on value, so the keys must exist and read null,
        // not be stripped — matching how total_cost/total_margin behave.
        $this->assertArrayHasKey('cost_price', $line);
        $this->assertArrayHasKey('line_cost', $line);
        $this->assertNull($line['cost_price']);
        $this->assertNull($line['line_cost']);
    }

    public function test_quote_on_an_empty_order_totals_zero(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');

        $issuance = $this->draftAt($loc);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");
        $res->assertOk();

        $this->assertSame([], $res->json('data.lines'));
        $this->assertEqualsWithDelta(0.0, (float) $res->json('data.subtotal'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $res->json('data.total_amount'), 0.001);
        $this->assertFalse($res->json('data.has_shortages'));
    }
}
