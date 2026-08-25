<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * ProductController must accept, persist and return the two new
 * appliance_details fields. Without the flattener change the fields save but
 * never come back, which reads to the frontend as "the save silently failed".
 */
class AcTypeProductFieldsTest extends LookupTestCase
{
    public function test_registering_an_appliance_stores_ac_type_and_unit_role(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();
        $category = $this->category();

        $response = $this->postJson('/api/products', [
            'product_name' => 'Test Split ' . uniqid(),
            'product_code' => 'PC-' . uniqid(),
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
            'ac_type_id'   => $type->ac_type_id,
            'unit_role'    => 'indoor',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.ac_type_id', $type->ac_type_id);
        $response->assertJsonPath('data.unit_role', 'indoor');
    }

    public function test_updating_an_appliance_changes_the_unit_role(): void
    {
        $this->actingAsAdmin();

        $detail = $this->applianceWith(['unit_role' => 'indoor']);

        $response = $this->putJson("/api/products/{$detail->product_id}", [
            'unit_role' => 'outdoor',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.unit_role', 'outdoor');
    }

    public function test_an_invalid_unit_role_is_rejected(): void
    {
        $this->actingAsAdmin();

        $category = $this->category();

        $this->postJson('/api/products', [
            'product_name' => 'Bad Role ' . uniqid(),
            'product_code' => 'PC-' . uniqid(),
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
            'unit_role'    => 'sideways',
        ])->assertStatus(422);
    }

    public function test_reading_an_appliance_returns_ac_type_and_unit_role(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();
        $detail = $this->applianceWith([
            'ac_type_id' => $type->ac_type_id,
            'unit_role'  => 'outdoor',
        ]);

        $show = $this->getJson("/api/products/{$detail->product_id}");
        $show->assertStatus(200);
        $show->assertJsonPath('data.ac_type_id', $type->ac_type_id);
        $show->assertJsonPath('data.unit_role', 'outdoor');
        $show->assertJsonPath('data.ac_type_name', $type->ac_type_name);

        $index = $this->getJson('/api/products?per_page=200');
        $index->assertStatus(200);

        $entry = collect($index->json('data'))->firstWhere('product_id', $detail->product_id);

        $this->assertNotNull($entry, 'product not found in index response');
        $this->assertSame($type->ac_type_id, $entry['ac_type_id']);
        $this->assertSame('outdoor', $entry['unit_role']);
        $this->assertSame($type->ac_type_name, $entry['ac_type_name']);
    }

    /**
     * Value-presence assertions alone do not catch a dropped acType
     * eager-load: Eloquent transparently lazy-loads an unloaded relation, so
     * $ad->acType?->ac_type_name still resolves correctly even without the
     * ->with(['brand', 'acType']) added for this task - it just costs one
     * extra query per appliance row instead of one query for the whole page.
     * That is exactly the N+1 the eager-load exists to prevent.
     *
     * The index() endpoint has other, pre-existing per-row query overhead
     * unrelated to this task (confirmed separately, out of scope here), so
     * asserting a flat *total* query count is unreliable. Instead this
     * isolates queries that touch ac_type_lookup specifically: eager-loaded,
     * that is exactly one batched query no matter how many appliance rows
     * are on the page; lazy-loaded, it is one query per row.
     */
    public function test_listing_appliances_batches_the_ac_type_lookup(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();

        $this->applianceWith(['ac_type_id' => $type->ac_type_id, 'unit_role' => 'indoor']);
        $this->applianceWith(['ac_type_id' => $type->ac_type_id, 'unit_role' => 'outdoor']);
        for ($i = 0; $i < 5; $i++) {
            $this->applianceWith(['ac_type_id' => $type->ac_type_id, 'unit_role' => 'indoor']);
        }

        DB::enableQueryLog();
        $this->getJson('/api/products?per_page=200')->assertStatus(200);
        $acTypeQueries = collect(DB::getQueryLog())
            ->filter(fn($q) => str_contains($q['query'], 'ac_type_lookup'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $acTypeQueries,
            'expected ac_type_lookup to be fetched in a single batched query - the acType eager-load is missing (N+1)'
        );
    }

    public function test_a_non_appliance_ignores_the_ac_fields(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();

        $response = $this->postJson('/api/products', [
            'product_name'  => 'Test Material ' . uniqid(),
            'product_code'  => 'PC-' . uniqid(),
            'product_type'  => 'installation_material',
            'ac_type_id'    => $type->ac_type_id,
            'unit_role'     => 'indoor',
        ]);

        $response->assertStatus(201);

        $product = Product::find($response->json('data.product_id'));

        $this->assertNull($product->applianceDetail);
    }
}
