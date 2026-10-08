<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\ProductSet;
use App\Services\IssuanceOrderService;
use App\Support\IssuanceStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class IssuanceOrderServiceTest extends SetTestCase
{
    private function draft(): Issuance
    {
        $loc      = $this->makeLocation();
        $customer = $this->makeCustomer();

        return Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $customer->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);
    }

    public function test_adding_a_set_expands_lines_multiplied_by_set_quantity(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial(price: 100);
        $b = $this->makeInstallationMaterial(price: 25);

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 2, 'sort_order' => 1]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2]);

        app(IssuanceOrderService::class)->addSet($issuance, $set->set_id, 3);

        $details = $issuance->fresh('details')->details;
        $this->assertCount(2, $details);
        $this->assertSame(6, (int) $details->firstWhere('product_id', $a->product_id)->quantity_requested);
        $this->assertSame(12, (int) $details->firstWhere('product_id', $b->product_id)->quantity_requested);
        $this->assertSame($set->label(), $issuance->fresh('sets')->sets->first()->set_name_snapshot);
    }

    public function test_adding_the_same_set_twice_creates_two_independent_groups(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $svc->addSet($issuance, $set->set_id, 2);

        $this->assertCount(2, $issuance->fresh('sets')->sets);
        $this->assertCount(2, $issuance->fresh('details')->details);
    }

    public function test_adding_a_custom_line_has_no_set_id(): void
    {
        $issuance = $this->draft();
        $p = $this->makeInstallationMaterial(price: 75);

        $detail = app(IssuanceOrderService::class)->addCustomLine($issuance, $p->product_id, 3);

        $this->assertNull($detail->issuance_set_id);
        $this->assertSame(3, (int) $detail->quantity_requested);
        $this->assertEqualsWithDelta(75.0, (float) $detail->unit_price, 0.001);
    }

    public function test_packaged_installation_material_is_priced_per_piece_in_the_order(): void
    {
        $issuance = $this->draft();
        $product = $this->makeInstallationMaterial(price: 50);
        $product->installationMaterialDetail()->create([
            'pieces_per_package' => 5,
            'package_unit' => 'box',
        ]);

        $detail = app(IssuanceOrderService::class)
            ->addCustomLine($issuance, $product->product_id, 4);

        $this->assertEqualsWithDelta(10.0, (float) $detail->unit_price, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $detail->subtotal, 0.001);
    }

    public function test_editing_a_set_line_flags_the_group_as_modified(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 5]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $detail = $issuance->fresh('details')->details->first();

        $svc->updateLine($issuance, (int) $detail->issuance_detail_id, 2);

        $this->assertTrue((bool) $issuance->fresh('sets')->sets->first()->was_modified);
        $this->assertSame(2, (int) $detail->fresh()->quantity_requested);
    }

    public function test_removing_a_set_line_also_flags_modified(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial();
        $b = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $detail = $issuance->fresh('details')->details->first();

        $svc->removeLine($issuance, (int) $detail->issuance_detail_id);

        $this->assertTrue((bool) $issuance->fresh('sets')->sets->first()->was_modified);
        $this->assertCount(1, $issuance->fresh('details')->details);
    }

    public function test_lines_cannot_be_changed_once_the_order_leaves_draft(): void
    {
        $issuance = $this->draft();
        $p = $this->makeInstallationMaterial();
        $issuance->update(['status_id' => IssuanceStatus::pending()]);

        $this->expectException(\RuntimeException::class);
        app(IssuanceOrderService::class)->addCustomLine($issuance, $p->product_id, 1);
    }

    public function test_set_line_with_fractional_quantity_rounds_to_whole_pieces_on_expansion(): void
    {
        $issuance = $this->draft();
        $product  = $this->makeInstallationMaterial(price: 10);

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        // A BOQ line of 1.5 lengths rounds up to 2 whole pieces before it is
        // multiplied out across the sets ordered.
        $set->lines()->create([
            'product_id' => $product->product_id,
            'quantity'   => 1.5,
            'sort_order' => 1,
        ]);

        app(IssuanceOrderService::class)->addSet($issuance, $set->set_id, 3);

        $detail = $issuance->fresh('details')->details->first();
        // round(1.5) = 2 pieces * 3 sets = 6 pieces
        $this->assertSame(6, (int) $detail->quantity_requested);
    }

    public function test_removing_a_custom_line_does_not_touch_any_set_group(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial();
        $custom = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $customDetail = $svc->addCustomLine($issuance, $custom->product_id, 1);

        $svc->removeLine($issuance, (int) $customDetail->issuance_detail_id);

        $this->assertFalse((bool) $issuance->fresh('sets')->sets->first()->was_modified);
        $this->assertCount(1, $issuance->fresh('details')->details);
    }

    public function test_update_line_refuses_a_detail_belonging_to_another_issuance(): void
    {
        $issuanceA = $this->draft();
        $issuanceB = $this->draft();
        $p = $this->makeInstallationMaterial();

        $svc = app(IssuanceOrderService::class);
        $detail = $svc->addCustomLine($issuanceA, $p->product_id, 1);

        $this->expectException(ModelNotFoundException::class);
        $svc->updateLine($issuanceB, (int) $detail->issuance_detail_id, 5);
    }

    public function test_remove_line_refuses_a_detail_belonging_to_another_issuance(): void
    {
        $issuanceA = $this->draft();
        $issuanceB = $this->draft();
        $p = $this->makeInstallationMaterial();

        $svc = app(IssuanceOrderService::class);
        $detail = $svc->addCustomLine($issuanceA, $p->product_id, 1);

        $this->expectException(ModelNotFoundException::class);
        $svc->removeLine($issuanceB, (int) $detail->issuance_detail_id);

        $this->assertCount(1, $issuanceA->fresh('details')->details);
    }

    public function test_add_set_with_zero_quantity_throws_and_leaves_no_partial_group(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);

        try {
            $svc->addSet($issuance, $set->set_id, 0);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        $this->assertCount(0, $issuance->fresh('sets')->sets);
        $this->assertCount(0, $issuance->fresh('details')->details);
    }

    public function test_add_set_with_negative_quantity_throws(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);

        $this->expectException(\InvalidArgumentException::class);
        $svc->addSet($issuance, $set->set_id, -1);
    }

    public function test_removing_the_last_line_of_a_group_leaves_the_empty_group_row_present(): void
    {
        $issuance = $this->draft();
        $a = $this->makeInstallationMaterial();

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $detail = $issuance->fresh('details')->details->first();

        $svc->removeLine($issuance, (int) $detail->issuance_detail_id);

        // Documented behavior: removeLine does not delete the now-empty
        // issuance_sets group row; it only flags was_modified.
        $this->assertCount(1, $issuance->fresh('sets')->sets);
        $this->assertCount(0, $issuance->fresh('details')->details);
    }

    public function test_updating_a_line_recomputes_its_subtotal(): void
    {
        $issuance = $this->draft();
        $p = $this->makeInstallationMaterial(price: 40);

        $svc    = app(IssuanceOrderService::class);
        $detail = $svc->addCustomLine($issuance, $p->product_id, 2);
        $this->assertEqualsWithDelta(80.0, (float) $detail->subtotal, 0.001);

        $updated = $svc->updateLine($issuance, (int) $detail->issuance_detail_id, 5);
        $this->assertEqualsWithDelta(200.0, (float) $updated->subtotal, 0.001);
    }

    /**
     * The addSet() null-product guard defends against a product row vanishing
     * under a set line. The schema already prevents that: product_set_lines
     * .product_id restricts deletion, so a product in use by a BOQ cannot be
     * hard-deleted at all. This proves that invariant — the guard is
     * belt-and-suspenders for FK-bypassing corruption, not an everyday path.
     */
    public function test_a_product_used_by_a_set_line_cannot_be_hard_deleted(): void
    {
        $product = $this->makeInstallationMaterial();
        $set     = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $product->product_id, 'quantity' => 1]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $product->forceDelete();
    }

    /**
     * An archived (soft-deleted) product still prices correctly, because
     * ProductSetLine::product() uses withTrashed(). The guard must NOT fire here.
     */
    public function test_adding_a_set_still_prices_a_soft_deleted_product(): void
    {
        $issuance = $this->draft();
        $product  = $this->makeInstallationMaterial(price: 90);

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $product->product_id, 'quantity' => 2]);

        $product->delete(); // soft delete

        app(IssuanceOrderService::class)->addSet($issuance, $set->set_id, 1);

        $detail = $issuance->fresh('details')->details->first();
        $this->assertEqualsWithDelta(90.0, (float) $detail->unit_price, 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $detail->subtotal, 0.001);
    }

    public function test_a_line_cannot_be_added_after_the_order_is_confirmed_under_the_lock(): void
    {
        // Simulates the race the lock closes: the caller holds a stale Draft
        // instance while the row has already flipped to Pending in the database.
        $issuance = $this->draft();
        $p        = $this->makeInstallationMaterial();

        $stale = Issuance::find($issuance->issuance_id);          // reads Draft
        Issuance::whereKey($issuance->issuance_id)                // someone confirms
            ->update(['status_id' => IssuanceStatus::pending()]);

        $this->expectException(\RuntimeException::class);
        app(IssuanceOrderService::class)->addCustomLine($stale, $p->product_id, 1);
    }
}
