<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;

class ProductSetModelTest extends SetTestCase
{
    public function test_set_computes_price_from_its_lines(): void
    {
        $a = $this->makeInstallationMaterial(price: 100);
        $b = $this->makeInstallationMaterial(price: 25);

        $set = ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 2, 'sort_order' => 1]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2]);

        $this->assertSame(2, $set->fresh('lines')->lines->count());
        $this->assertEqualsWithDelta(300.00, $set->fresh('lines')->totalPrice(), 0.001);
    }

    public function test_hard_deleting_a_set_removes_its_lines(): void
    {
        $p = $this->makeInstallationMaterial();
        $set = ProductSet::create(['capacity_rating' => '1HP', 'variant' => 'inverter']);
        $set->lines()->create(['product_id' => $p->product_id, 'quantity' => 1]);

        $setId = $set->set_id;
        $set->forceDelete();

        $this->assertSame(0, \App\Models\ProductSetLine::where('set_id', $setId)->count());
    }

    public function test_soft_deleting_a_set_keeps_its_lines_so_restore_works(): void
    {
        $p = $this->makeInstallationMaterial();
        $set = ProductSet::create(['capacity_rating' => '2.5HP', 'variant' => 'inverter']);
        $set->lines()->create(['product_id' => $p->product_id, 'quantity' => 3]);

        $setId = $set->set_id;
        $set->delete();

        $this->assertSame(1, \App\Models\ProductSetLine::where('set_id', $setId)->count());

        $set->restore();
        $this->assertSame(1, $set->fresh('lines')->lines->count());
    }

    public function test_label_is_derived_from_brands_capacity_and_variant(): void
    {
        $set = \App\Models\ProductSet::create(['capacity_rating' => '2HP', 'variant' => 'non-inverter']);
        // Use two real brand_lookup rows.
        $brandIds = \Illuminate\Support\Facades\DB::table('brand_lookup')->orderBy('brand_id')->limit(2)->pluck('brand_id')->all();
        $this->assertNotEmpty($brandIds, 'need brand_lookup rows');
        $set->brands()->sync($brandIds);

        $label = $set->fresh('brands')->label();
        $this->assertStringContainsString('2HP', $label);
        $this->assertStringContainsString('non-inverter', $label);
        // Contains at least the first brand's name.
        $firstBrandName = \Illuminate\Support\Facades\DB::table('brand_lookup')->where('brand_id', $brandIds[0])->value('brand_name');
        $this->assertStringContainsString($firstBrandName, $label);
    }
}
