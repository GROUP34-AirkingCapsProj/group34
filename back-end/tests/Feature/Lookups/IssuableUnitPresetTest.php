<?php

namespace Tests\Feature\Lookups;

use App\Models\IssuableUnitPreset;
use App\Models\Product;
use App\Models\UnitLookup;
use Illuminate\Support\Facades\DB;

/**
 * The trait's generic CRUD (index/show/store/update/destroy/archived/
 * restore/forceDestroy) is already proven exhaustively against
 * CapacityLookupTest. These tests cover only what is specific to issuable
 * unit presets: the preset-only fields, the unit_id foreign key, and above
 * all that a preset is a template — renaming it must NOT rewrite any
 * product's product_issuable_units row.
 */
class IssuableUnitPresetTest extends LookupTestCase
{
    protected function unit(): UnitLookup
    {
        return UnitLookup::create([
            'unit_name'         => 'Piece ' . uniqid(),
            'unit_abbreviation' => 'pc',
        ]);
    }

    /**
     * Creates a product carrying a product_issuable_units row with the given
     * label, pointing at the given unit.
     */
    protected function productWithIssuableUnit(UnitLookup $unit, string $label): Product
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Test Product ' . uniqid(),
            'product_type' => 'appliance',
        ]);

        DB::table('product_issuable_units')->insert([
            'product_id'            => $product->product_id,
            'unit_id'               => $unit->unit_id,
            'label'                 => $label,
            'conversion_factor'     => 1,
            'is_whole_package'      => false,
            'sort_order'            => 0,
            'show_as_quick_action'  => true,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return $product;
    }

    public function test_create_stores_conversion_factor_and_whole_package_flag(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = $this->uniqueValue();

        $response = $this->postJson('/api/issuable-unit-presets', [
            'label'                     => $label,
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => 50,
            'is_whole_package'          => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('issuable_unit_preset', [
            'label'                     => $label,
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => 50,
            'is_whole_package'          => 1,
        ]);
    }

    public function test_unit_id_must_reference_an_existing_unit(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/issuable-unit-presets', [
            'label'   => $this->uniqueValue(),
            'unit_id' => 999999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['unit_id']);
    }

    public function test_labels_are_unique(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = $this->uniqueValue();

        IssuableUnitPreset::create([
            'label'   => $label,
            'unit_id' => $unit->unit_id,
        ]);

        $response = $this->postJson('/api/issuable-unit-presets', [
            'label'   => $label,
            'unit_id' => $unit->unit_id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['label' => 'has already been taken']);
    }

    public function test_usage_count_reflects_products_using_the_label(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = $this->uniqueValue();

        $preset = IssuableUnitPreset::create([
            'label'   => $label,
            'unit_id' => $unit->unit_id,
        ]);

        $this->productWithIssuableUnit($unit, $label);

        // A second row differing only in case must NOT count: this is what
        // makes the sargable BINARY pair in usageCountFor() worth having —
        // a bare ->where('label', $value) alone (or a match on unit_id
        // instead of label) would also pass with just the first row.
        $this->productWithIssuableUnit($unit, strtolower($label));

        $response = $this->getJson('/api/issuable-unit-presets/' . $preset->preset_id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.usage_count', 1);
    }

    public function test_index_lists_the_preset_with_its_nested_unit(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = $this->uniqueValue();

        IssuableUnitPreset::create([
            'label'   => $label,
            'unit_id' => $unit->unit_id,
        ]);

        $response = $this->getJson('/api/issuable-unit-presets');

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('label', $label);

        $this->assertNotNull($row, 'The created preset must appear in the index');
        $this->assertSame($unit->unit_name, $row['unit']['unit_name'] ?? null);
    }

    public function test_show_returns_the_preset_with_its_nested_unit(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        $preset = IssuableUnitPreset::create([
            'label'   => $this->uniqueValue(),
            'unit_id' => $unit->unit_id,
        ]);

        $response = $this->getJson('/api/issuable-unit-presets/' . $preset->preset_id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.unit.unit_name', $unit->unit_name);
    }

    /**
     * store()/update() call ->fresh($this->lookupEagerLoads()), not a bare
     * ->fresh() — Model::fresh() defaults to loading no relations at all, so
     * a bare call would come back without `unit` even though index()/show()/
     * archived() always include it (they all route through
     * lookupEagerLoads() too). A frontend splicing a create/update response
     * straight into its list would blank the Unit column until a refetch;
     * this pins that the write responses already carry it.
     */
    public function test_create_and_update_responses_carry_the_nested_unit(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = $this->uniqueValue();

        $createResponse = $this->postJson('/api/issuable-unit-presets', [
            'label'   => $label,
            'unit_id' => $unit->unit_id,
        ]);

        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('data.unit.unit_id', $unit->unit_id);
        $createResponse->assertJsonPath('data.unit.unit_name', $unit->unit_name);

        $presetId = $createResponse->json('data.preset_id');
        $otherUnit = $this->unit();

        $updateResponse = $this->putJson("/api/issuable-unit-presets/{$presetId}", [
            'unit_id' => $otherUnit->unit_id,
        ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.unit.unit_id', $otherUnit->unit_id);
        $updateResponse->assertJsonPath('data.unit.unit_name', $otherUnit->unit_name);
    }

    public function test_renaming_a_preset_does_not_touch_product_rows(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $originalLabel = $this->uniqueValue();
        $newLabel = $this->uniqueValue();

        $preset = IssuableUnitPreset::create([
            'label'   => $originalLabel,
            'unit_id' => $unit->unit_id,
        ]);

        $product = $this->productWithIssuableUnit($unit, $originalLabel);

        $response = $this->putJson('/api/issuable-unit-presets/' . $preset->preset_id, [
            'label' => $newLabel,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('product_issuable_units', [
            'product_id' => $product->product_id,
            'label'      => $originalLabel,
        ]);
        $this->assertDatabaseMissing('product_issuable_units', [
            'product_id' => $product->product_id,
            'label'      => $newLabel,
        ]);
    }

    /**
     * Merge is deliberately unrouted (see IssuableUnitPresetController): with
     * no cascade, the trait's merge() would archive the source and move
     * nothing, silently orphaning the source's label everywhere it was used.
     * There is no /merge route to hit, so the strongest test available at
     * the HTTP boundary is that the route itself doesn't exist. The
     * trait-level guard (supportsMerge() === false -> 422) is exercised by
     * CapacityLookupTest/VariantLookupTest already having the true case; here
     * the absence of the route is the actual product behaviour being pinned.
     */
    public function test_merge_route_does_not_exist(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        $preset = IssuableUnitPreset::create([
            'label'   => $this->uniqueValue(),
            'unit_id' => $unit->unit_id,
        ]);

        $response = $this->postJson('/api/issuable-unit-presets/' . $preset->preset_id . '/merge', [
            'target_id' => $preset->preset_id,
        ]);

        $response->assertStatus(404);
    }

    public function test_default_conversion_factor_of_zero_is_rejected(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        $response = $this->postJson('/api/issuable-unit-presets', [
            'label'                     => $this->uniqueValue(),
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['default_conversion_factor']);
    }

    /**
     * The column is decimal(10,6), max 9999.999999. A value at that ceiling
     * must be accepted; 10000 (and anything that would round up into it,
     * or overflow via scientific notation) must be rejected with 422 rather
     * than reaching MySQL and raising SQLSTATE 22003.
     */
    public function test_default_conversion_factor_boundary(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        $atCeiling = $this->postJson('/api/issuable-unit-presets', [
            'label'                     => $this->uniqueValue(),
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => '9999.999999',
        ]);
        $atCeiling->assertStatus(201);

        $overCeiling = $this->postJson('/api/issuable-unit-presets', [
            'label'                     => $this->uniqueValue(),
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => 10000,
        ]);
        $overCeiling->assertStatus(422);
        $overCeiling->assertJsonValidationErrors(['default_conversion_factor']);

        // Would round UP to 10000.000000 on write if only lt:10000 guarded
        // the column, since decimal:0,6 is what actually rejects it.
        $roundsIntoOverflow = $this->postJson('/api/issuable-unit-presets', [
            'label'                     => $this->uniqueValue(),
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => '9999.9999995',
        ]);
        $roundsIntoOverflow->assertStatus(422);
        $roundsIntoOverflow->assertJsonValidationErrors(['default_conversion_factor']);

        // Scientific notation passes the bare `numeric` rule but must be
        // caught by decimal:0,6 before it can overflow the column.
        $scientificNotation = $this->postJson('/api/issuable-unit-presets', [
            'label'                     => $this->uniqueValue(),
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => '1e10',
        ]);
        $scientificNotation->assertStatus(422);
        $scientificNotation->assertJsonValidationErrors(['default_conversion_factor']);
    }

    public function test_sort_order_null_is_rejected_with_422_not_500(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        $response = $this->postJson('/api/issuable-unit-presets', [
            'label'      => $this->uniqueValue(),
            'unit_id'    => $unit->unit_id,
            'sort_order' => null,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sort_order']);
    }

    public function test_label_max_width_boundary(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        $exactly100 = str_repeat('a', 100);
        $ok = $this->postJson('/api/issuable-unit-presets', [
            'label'   => $exactly100,
            'unit_id' => $unit->unit_id,
        ]);
        $ok->assertStatus(201);

        $over100 = str_repeat('b', 101);
        $tooLong = $this->postJson('/api/issuable-unit-presets', [
            'label'   => $over100,
            'unit_id' => $unit->unit_id,
        ]);
        $tooLong->assertStatus(422);
        $tooLong->assertJsonValidationErrors(['label']);
    }
}
