<?php

namespace Tests\Feature\Sets;

use App\Models\ApplianceDetail;
use App\Models\ProductSet;
use Illuminate\Support\Facades\DB;

class SetSuggestionTest extends SetTestCase
{
    private function makeAirconWithDetail(?int $brandId, string $capacity, string $variant)
    {
        $product = $this->makeAppliance();
        ApplianceDetail::create([
            'product_id'      => $product->product_id,
            'brand_id'        => $brandId,
            'capacity_rating' => $capacity,
            'variant'         => $variant,
            'is_serialized'   => true,
        ]);

        return $product;
    }

    public function test_exact_brand_capacity_variant_match_scores_highest(): void
    {
        $loc     = $this->makeLocation();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');
        $brandId = (int) DB::table('brand_lookup')->min('brand_id');

        $exact = ProductSet::create([
            'set_code' => 'S1-' . uniqid(), 'set_name' => 'Exact',
            'brand_id' => $brandId, 'capacity_rating' => '3TR/4HP', 'variant' => 'non-inverter',
        ]);
        ProductSet::create([
            'set_code' => 'S2-' . uniqid(), 'set_name' => 'Capacity only',
            'capacity_rating' => '3TR/4HP',
        ]);
        ProductSet::create([
            'set_code' => 'S3-' . uniqid(), 'set_name' => 'Wrong capacity',
            'brand_id' => $brandId, 'capacity_rating' => '1TR', 'variant' => 'non-inverter',
        ]);

        $aircon = $this->makeAirconWithDetail($brandId, '3TR/4HP', 'non-inverter');

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $aircon->product_id);

        $res->assertOk();
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame((int) $exact->set_id, (int) $data[0]['set_id']);
        // The mismatched-capacity set must not be offered at all.
        $this->assertNotContains('Wrong capacity', array_column($data, 'set_name'));
    }

    public function test_capacity_match_alone_is_still_suggested(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        ProductSet::create([
            'set_code' => 'S4-' . uniqid(), 'set_name' => 'Generic 2TR',
            'capacity_rating' => '2TR',
        ]);
        $aircon = $this->makeAirconWithDetail(null, '2TR', 'inverter');

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $aircon->product_id);

        $res->assertOk();
        $this->assertContains('Generic 2TR', array_column($res->json('data'), 'set_name'));
    }

    public function test_capacity_comparison_ignores_case_and_spacing(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        ProductSet::create([
            'set_code' => 'S5-' . uniqid(), 'set_name' => 'Spaced',
            'capacity_rating' => '3tr / 4hp',
        ]);
        $aircon = $this->makeAirconWithDetail(null, '3TR/4HP', 'non-inverter');

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $aircon->product_id);

        $this->assertContains('Spaced', array_column($res->json('data'), 'set_name'));
    }

    public function test_non_appliance_product_returns_empty_suggestions(): void
    {
        $loc      = $this->makeLocation();
        $user     = $this->makeUserAt($loc, 'warehouse_personnel');
        $material = $this->makeConsumable();

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $material->product_id);

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
}
