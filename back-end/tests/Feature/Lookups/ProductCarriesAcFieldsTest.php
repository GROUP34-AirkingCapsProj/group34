<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\Product;

/**
 * unit_role and ac_type_name must travel with a serialised Product wherever it
 * appears — not only through ProductController, which sets them by hand.
 *
 * The operational reason: an indoor unit and its outdoor partner share a
 * brand, a capacity and a near-identical name, so on a receiving or issuance
 * screen they are indistinguishable. Staff read the role to know which carton
 * they are looking at. Any screen that serialises a product has to carry it.
 */
class ProductCarriesAcFieldsTest extends LookupTestCase
{
    private function aircon(string $role, string $typeName = 'Split Type'): Product
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Aircon ' . uniqid(),
            'product_type' => 'appliance',
        ]);

        ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'unit_role'     => $role,
            'ac_type_id'    => AcTypeLookup::where('ac_type_name', $typeName)->value('ac_type_id'),
            'is_serialized' => true,
        ]);

        return $product->fresh();
    }

    public function test_a_serialised_product_carries_its_unit_role(): void
    {
        $product = $this->aircon('indoor');

        $this->assertSame('indoor', $product->toArray()['unit_role']);
    }

    public function test_a_serialised_product_carries_its_ac_type_name(): void
    {
        $product = $this->aircon('outdoor', 'Cassette');

        $this->assertSame('Cassette', $product->toArray()['ac_type_name']);
    }

    /**
     * The point of appending on the model rather than in a controller: a
     * product reached through a relation — the way receiving, issuance and
     * incident screens reach it — carries the fields too.
     */
    public function test_the_fields_survive_being_reached_through_a_relation(): void
    {
        $product = $this->aircon('outdoor');

        $reloaded = Product::with('applianceDetail.acType')
            ->findOrFail($product->product_id)
            ->toArray();

        $this->assertSame('outdoor', $reloaded['unit_role']);
        $this->assertSame('Split Type', $reloaded['ac_type_name']);
    }

    public function test_a_non_appliance_carries_nulls_rather_than_blowing_up(): void
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Copper Pipe ' . uniqid(),
            'product_type' => 'installation_material',
        ]);

        $array = $product->fresh()->toArray();

        $this->assertNull($array['unit_role']);
        $this->assertNull($array['ac_type_name']);
    }

    public function test_an_appliance_with_no_ac_type_carries_a_null_name(): void
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Television ' . uniqid(),
            'product_type' => 'appliance',
        ]);

        ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'unit_role'     => 'complete',
            'is_serialized' => true,
        ]);

        $array = $product->fresh()->toArray();

        $this->assertSame('complete', $array['unit_role']);
        $this->assertNull($array['ac_type_name']);
    }

    /**
     * An eager-loaded listing must not pay a query per row for these two
     * accessors. This is the guard on the cost of appending them globally.
     */
    public function test_an_eager_loaded_listing_does_not_query_per_product(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->aircon('indoor');
        }

        \DB::enableQueryLog();

        Product::with('applianceDetail.acType')
            ->where('product_type', 'appliance')
            ->limit(6)
            ->get()
            ->toArray();

        $applianceQueries = collect(\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'appliance_details'))
            ->count();

        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            1,
            $applianceQueries,
            'appliance_details must be fetched in one batched query - the accessors lazy-load, so a listing that does not eager-load pays per row'
        );
    }
}
