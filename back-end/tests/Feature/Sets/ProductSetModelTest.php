<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;

class ProductSetModelTest extends SetTestCase
{
    public function test_set_computes_price_from_its_lines(): void
    {
        $a = $this->makeConsumable(price: 100);
        $b = $this->makeConsumable(price: 25);

        $set = ProductSet::create([
            'set_code' => 'SET-' . uniqid(),
            'set_name' => 'Std Installation Materials',
        ]);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 2, 'sort_order' => 1]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2]);

        $this->assertSame(2, $set->fresh('lines')->lines->count());
        $this->assertEqualsWithDelta(300.00, $set->fresh('lines')->setPrice(), 0.001);
    }

    public function test_hard_deleting_a_set_removes_its_lines(): void
    {
        $p = $this->makeConsumable();
        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Temp']);
        $set->lines()->create(['product_id' => $p->product_id, 'quantity' => 1]);

        $setId = $set->set_id;
        $set->forceDelete();

        $this->assertSame(0, \App\Models\ProductSetLine::where('set_id', $setId)->count());
    }

    public function test_soft_deleting_a_set_keeps_its_lines_so_restore_works(): void
    {
        $p = $this->makeConsumable();
        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Archived']);
        $set->lines()->create(['product_id' => $p->product_id, 'quantity' => 3]);

        $setId = $set->set_id;
        $set->delete();

        $this->assertSame(1, \App\Models\ProductSetLine::where('set_id', $setId)->count());

        $set->restore();
        $this->assertSame(1, $set->fresh('lines')->lines->count());
    }
}
