<?php

namespace Tests\Feature\Sets;

use App\Models\ApplianceDetail;
use App\Models\ProductSet;
use Illuminate\Support\Facades\DB;

class SetSuggestionTest extends SetTestCase
{
    private function makeAirconWithDetail(?int $brandId, ?string $capacity, ?string $variant)
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

    private function suggestFor(int $productId, string $role = 'warehouse_personnel'): array
    {
        $user = $this->makeUserAt($this->makeLocation(), $role);

        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/product-sets/suggest?product_id=' . $productId)
            ->assertOk()
            ->json('data');
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

    /**
     * A third of appliance_details rows carry no capacity — soundbars, TVs, some
     * chilled-water units. There is nothing to anchor a match on, so suggesting
     * every BOQ in the catalogue would be noise on a third of all selections.
     */
    public function test_an_appliance_without_a_capacity_gets_no_suggestions(): void
    {
        ProductSet::create([
            'set_code' => 'S6-' . uniqid(), 'set_name' => 'Should not appear',
            'capacity_rating' => '3TR/4HP',
        ]);
        ProductSet::create([
            'set_code' => 'S7-' . uniqid(), 'set_name' => 'Generic wildcard',
        ]);

        $soundbar = $this->makeAirconWithDetail(null, null, null);

        $this->assertSame([], $this->suggestFor((int) $soundbar->product_id));
    }

    public function test_an_empty_capacity_string_is_treated_as_unspecified(): void
    {
        ProductSet::create([
            'set_code' => 'S8-' . uniqid(), 'set_name' => 'Real capacity',
            'capacity_rating' => '3TR/4HP',
        ]);

        // Written straight through Eloquent, bypassing the middleware that would
        // otherwise rewrite '' to null on an HTTP request.
        $blank = $this->makeAirconWithDetail(null, '   ', null);

        $this->assertSame([], $this->suggestFor((int) $blank->product_id));
    }

    public function test_a_set_with_no_capacity_acts_as_a_wildcard(): void
    {
        ProductSet::create([
            'set_code' => 'S9-' . uniqid(), 'set_name' => 'Applies to any capacity',
        ]);

        $aircon = $this->makeAirconWithDetail(null, '5TR', 'inverter');
        $names  = array_column($this->suggestFor((int) $aircon->product_id), 'set_name');

        $this->assertContains('Applies to any capacity', $names);
    }

    public function test_inactive_sets_are_never_suggested(): void
    {
        ProductSet::create([
            'set_code' => 'S10-' . uniqid(), 'set_name' => 'Retired BOQ',
            'capacity_rating' => '4TR', 'is_active' => false,
        ]);
        ProductSet::create([
            'set_code' => 'S11-' . uniqid(), 'set_name' => 'Live BOQ',
            'capacity_rating' => '4TR', 'is_active' => true,
        ]);

        $aircon = $this->makeAirconWithDetail(null, '4TR', null);
        $names  = array_column($this->suggestFor((int) $aircon->product_id), 'set_name');

        $this->assertContains('Live BOQ', $names);
        $this->assertNotContains('Retired BOQ', $names);
    }

    /** Ordering alone would stay green if a weight were wrong, so pin the arithmetic. */
    public function test_match_score_reflects_the_documented_weights(): void
    {
        $brandId = (int) DB::table('brand_lookup')->min('brand_id');

        ProductSet::create([
            'set_code' => 'S12-' . uniqid(), 'set_name' => 'All three',
            'brand_id' => $brandId, 'capacity_rating' => '6TR', 'variant' => 'inverter',
        ]);
        ProductSet::create([
            'set_code' => 'S13-' . uniqid(), 'set_name' => 'Capacity only',
            'capacity_rating' => '6TR',
        ]);

        $aircon = $this->makeAirconWithDetail($brandId, '6TR', 'inverter');
        $byName = collect($this->suggestFor((int) $aircon->product_id))->keyBy('set_name');

        $this->assertSame(7, (int) $byName['All three']['match_score']);
        $this->assertSame(4, (int) $byName['Capacity only']['match_score']);
    }

    public function test_a_product_that_does_not_exist_is_rejected(): void
    {
        $user = $this->makeUserAt($this->makeLocation(), 'warehouse_personnel');

        $this->actingAs($user, 'sanctum')
             ->getJson('/api/product-sets/suggest?product_id=99999999')
             ->assertStatus(422)
             ->assertJsonValidationErrors('product_id');
    }
}
