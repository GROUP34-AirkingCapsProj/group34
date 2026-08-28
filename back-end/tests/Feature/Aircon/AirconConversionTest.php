<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\ApplianceDetail;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;

/**
 * The legacy-aircon conversion tool.
 *
 * Two of these tests are load-bearing rather than merely thorough:
 *
 *  - test_existing_references_survive_the_conversion is the guarantee the
 *    whole design rests on. The tool exists in this shape — rewriting a row
 *    rather than replacing it — precisely so that serials, inventory and
 *    document lines already pointing at the legacy product keep pointing at a
 *    live one. If that test fails the tool must be withdrawn, not patched.
 *
 *  - test_a_split_that_does_not_sum_to_the_original_price_is_rejected is the
 *    guard against a silent repricing of the catalogue. Verified by deleting
 *    the guard and watching this test fail with a 201.
 */
class AirconConversionTest extends AirconTestCase
{
    /**
     * A legacy aircon: one product carrying the whole machine, unit_role
     * 'complete', at the given price.
     */
    private function legacyProduct(float $price = 50000.00): Product
    {
        $product = Product::create([
            'product_code'       => 'LEG-' . uniqid(),
            'product_name'       => 'Legacy Aircon ' . uniqid(),
            'product_type'       => 'appliance',
            'description'        => 'A whole aircon on one row.',
            'unit_price'         => $price,
            'recommended_stocks' => 7,
            'safety_stock'       => 3,
            'max_stock_level'    => 25,
        ]);

        ApplianceDetail::create([
            'product_id'      => $product->product_id,
            'brand_id'        => $this->brand()->brand_id,
            'model_code'      => 'LEGACY-CODE',
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'unit_role'       => 'complete',
            'color'           => 'White',
            'warranty_value'  => 2,
            'warranty_unit'   => 'year',
            'is_serialized'   => true,
        ]);

        return $product->fresh();
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'indoor'  => ['model_code' => 'IDU-100', 'unit_price' => 20000.00],
            'outdoor' => ['model_code' => 'ODU-100', 'unit_price' => 30000.00],
            'keep_as' => 'indoor',
        ], $overrides);
    }

    private function convert(Product $product, array $overrides = [])
    {
        return $this->postJson(
            "/api/products/{$product->product_id}/convert-to-two-piece",
            $this->payload($overrides)
        );
    }

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Conversion Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    // ------------------------------------------------------- the original row

    public function test_the_original_product_keeps_its_id_and_takes_the_kept_role(): void
    {
        $this->actingAsAdmin();

        $legacy   = $this->legacyProduct(50000.00);
        $originalId = $legacy->product_id;

        $this->convert($legacy, ['keep_as' => 'indoor'])->assertStatus(201);

        // Same row, not a replacement. withTrashed() so a conversion that
        // archived the original would still be caught here as "trashed"
        // rather than silently reported as "gone".
        $after = Product::withTrashed()->find($originalId);

        $this->assertNotNull($after, 'the original product row no longer exists');
        $this->assertFalse($after->trashed(), 'the original product was archived by the conversion');
        $this->assertSame($originalId, (int) $after->product_id);
        $this->assertSame(20000.00, (float) $after->unit_price);

        $detail = ApplianceDetail::where('product_id', $originalId)->first();
        $this->assertSame('indoor', $detail->unit_role);
        $this->assertSame('IDU-100', $detail->model_code);
    }

    public function test_keeping_the_original_as_the_outdoor_side_gives_it_the_outdoor_price(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $this->convert($legacy, ['keep_as' => 'outdoor'])->assertStatus(201);

        $after  = Product::find($legacy->product_id);
        $detail = ApplianceDetail::where('product_id', $legacy->product_id)->first();

        $this->assertSame(30000.00, (float) $after->unit_price);
        $this->assertSame('outdoor', $detail->unit_role);
        $this->assertSame('ODU-100', $detail->model_code);
    }

    // ---------------------------------------------------------- the partner

    public function test_the_partner_is_a_new_product_carrying_the_other_side(): void
    {
        $this->actingAsAdmin();

        $legacy   = $this->legacyProduct(50000.00);
        $response = $this->convert($legacy, ['keep_as' => 'indoor']);

        $response->assertStatus(201);

        $units = collect($response->json('data.units'));
        $this->assertCount(2, $units);

        $partnerId = (int) $units->firstWhere('unit_role', 'outdoor')['product_id'];

        $this->assertNotSame((int) $legacy->product_id, $partnerId, 'the partner is the same row as the original');

        $partner = Product::find($partnerId);
        $this->assertNotNull($partner);
        $this->assertSame(30000.00, (float) $partner->unit_price);
        $this->assertSame('appliance', $partner->product_type);

        // Its own code, derived but distinct.
        $this->assertNotSame($legacy->product_code, $partner->product_code);
        $this->assertStringStartsWith($legacy->product_code, $partner->product_code);

        // Carried over from the original.
        $this->assertSame($legacy->description, $partner->description);
        $this->assertSame(7, (int) $partner->recommended_stocks);
        $this->assertSame(3, (int) $partner->safety_stock);
        $this->assertSame(25, (int) $partner->max_stock_level);

        $legacyDetail  = ApplianceDetail::where('product_id', $legacy->product_id)->first();
        $partnerDetail = ApplianceDetail::where('product_id', $partnerId)->first();

        $this->assertNotNull($partnerDetail);
        $this->assertSame('outdoor', $partnerDetail->unit_role);
        $this->assertSame('ODU-100', $partnerDetail->model_code);
        $this->assertSame((int) $legacyDetail->brand_id, (int) $partnerDetail->brand_id);
        $this->assertSame('2HP', $partnerDetail->capacity_rating);
        $this->assertSame('Inverter', $partnerDetail->variant);
        $this->assertSame((int) $legacyDetail->ac_type_id, (int) $partnerDetail->ac_type_id);
        $this->assertSame('White', $partnerDetail->color);
        $this->assertSame(2, (int) $partnerDetail->warranty_value);
        $this->assertSame('year', $partnerDetail->warranty_unit);
        $this->assertTrue((bool) $partnerDetail->is_serialized);
    }

    // ----------------------------------------------------- the price guard

    public function test_a_split_that_does_not_sum_to_the_original_price_is_rejected(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $response = $this->convert($legacy, [
            'indoor'  => ['model_code' => 'IDU-100', 'unit_price' => 20000.00],
            'outdoor' => ['model_code' => 'ODU-100', 'unit_price' => 25000.00],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('unit_price');

        // Both figures named, so the user can see what is wrong without
        // reaching for a calculator.
        $message = $response->json('errors.unit_price.0');
        $this->assertStringContainsString('45,000.00', $message);
        $this->assertStringContainsString('50,000.00', $message);

        // And nothing happened: no repricing, no role change, no partner.
        $legacy->refresh();
        $this->assertSame(50000.00, (float) $legacy->unit_price);
        $this->assertSame('complete', ApplianceDetail::where('product_id', $legacy->product_id)->value('unit_role'));
        $this->assertSame(
            0,
            Product::where('product_code', 'like', $legacy->product_code . '-%')->count(),
            'a rejected conversion left a partner product behind'
        );
    }

    public function test_a_split_that_sums_within_rounding_is_accepted(): void
    {
        $this->actingAsAdmin();

        // 33333.33 + 16666.67 = 50000.00 exactly at 2dp, but not in binary
        // floating point. The guard compares rounded to 2dp, so this passes.
        $legacy = $this->legacyProduct(50000.00);

        $this->convert($legacy, [
            'indoor'  => ['model_code' => 'IDU-100', 'unit_price' => 33333.33],
            'outdoor' => ['model_code' => 'ODU-100', 'unit_price' => 16666.67],
        ])->assertStatus(201);
    }

    // ------------------------------------------------------- the guard rails

    public function test_a_product_that_is_already_a_side_cannot_be_split_again(): void
    {
        $this->actingAsAdmin();

        foreach (['indoor', 'outdoor'] as $role) {
            $product = $this->unitProduct($role, 50000.00);

            $this->convert($product)
                 ->assertStatus(422)
                 ->assertJsonValidationErrors('product');

            $this->assertSame(
                $role,
                ApplianceDetail::where('product_id', $product->product_id)->value('unit_role'),
                "converting an already-{$role} product changed its role"
            );
        }
    }

    public function test_a_product_with_no_appliance_details_is_rejected(): void
    {
        $this->actingAsAdmin();

        $consumable = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Copper Tubing ' . uniqid(),
            'product_type' => 'installation_material',
            'unit_price'   => 50000.00,
        ]);

        $this->convert($consumable)
             ->assertStatus(422)
             ->assertJsonValidationErrors('product');

        $this->assertSame(0, ApplianceDetail::where('product_id', $consumable->product_id)->count());
    }

    public function test_a_one_piece_ac_type_cannot_be_split(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);
        ApplianceDetail::where('product_id', $legacy->product_id)
            ->update(['ac_type_id' => $this->windowType()->ac_type_id]);

        $this->convert($legacy)
             ->assertStatus(422)
             ->assertJsonValidationErrors('product');
    }

    // ---------------------------------------------------------- the composer

    public function test_the_composed_model_is_two_piece_with_one_line_per_side(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $response = $this->convert($legacy, ['model_name' => 'Split 2HP Inverter']);
        $response->assertStatus(201);

        $data = $response->json('data');

        $this->assertSame('Split 2HP Inverter', $data['model_name']);
        $this->assertTrue($data['is_two_piece']);
        $this->assertSame(50000.00, (float) $data['total_price']);

        $roles = collect($data['units'])->pluck('unit_role')->sort()->values()->all();
        $this->assertSame(['indoor', 'outdoor'], $roles);

        foreach ($data['units'] as $unit) {
            $this->assertSame(1, (int) $unit['quantity']);
        }

        // Actually persisted through the composer, not just echoed back.
        $model = AirconModel::with('units')->find($data['aircon_model_id']);
        $this->assertNotNull($model);
        $this->assertCount(2, $model->units);
        $this->assertTrue((bool) $model->is_two_piece);
        $this->assertSame('2HP', $model->capacity_rating);
        $this->assertSame('Inverter', $model->variant);
        $this->assertSame((int) $this->splitType()->ac_type_id, (int) $model->ac_type_id);

        // The original product is one of the two lines — the composer bound
        // the existing row, it did not compose a pair of new ones.
        $this->assertContains(
            (int) $legacy->product_id,
            $model->units->pluck('product_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_the_model_name_defaults_to_the_product_name(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $response = $this->convert($legacy);
        $response->assertStatus(201);

        $this->assertSame($legacy->product_name, $response->json('data.model_name'));
    }

    // ------------------------------------------------- THE ONE THAT MATTERS

    /**
     * Everything already pointing at the legacy product must still point at a
     * live product afterwards, by the same foreign key.
     *
     * This is not a formality. The whole reason the original row is rewritten
     * rather than replaced is that this project cannot rewrite the four tables
     * that reference it. If the conversion ever starts creating two fresh
     * products and archiving the original, this test is what notices.
     */
    public function test_existing_references_survive_the_conversion(): void
    {
        $this->actingAsAdmin();

        $legacy     = $this->legacyProduct(50000.00);
        $originalId = (int) $legacy->product_id;
        $location   = $this->location();

        $serial = ItemSerial::create([
            'product_id'    => $originalId,
            'location_id'   => $location->location_id,
            'serial_number' => 'SN-' . uniqid(),
            'serial_type'   => 'unit',
        ]);

        $inventory = Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $originalId,
            'quantity_on_hand'   => 4,
            'available_quantity' => 4,
        ]);

        $this->convert($legacy, ['keep_as' => 'indoor'])->assertStatus(201);

        // 1. The foreign keys still hold the same id.
        $serial->refresh();
        $inventory->refresh();
        $this->assertSame($originalId, (int) $serial->product_id);
        $this->assertSame($originalId, (int) $inventory->product_id);

        // 2. That id still resolves to a live, untrashed product — an
        //    archived original would leave both rows unusable, which is the
        //    exact failure mode this design exists to prevent.
        $this->assertNotNull($serial->product, 'the serial no longer resolves to a product');
        $this->assertNotNull($inventory->product, 'the inventory row no longer resolves to a product');
        $this->assertFalse($serial->product->trashed());
        $this->assertFalse($inventory->product->trashed());
        $this->assertSame($originalId, (int) $serial->product->product_id);

        // 3. Quantities were not disturbed on the way through.
        $this->assertSame(4, (int) $inventory->quantity_on_hand);
        $this->assertSame(4, (int) $inventory->available_quantity);

        // 4. And the row those references land on is now a real half of the
        //    pair, not an orphan left outside the model.
        $this->assertSame(
            'indoor',
            ApplianceDetail::where('product_id', $originalId)->value('unit_role')
        );

        $model = AirconModel::with('units')->latest('aircon_model_id')->first();
        $this->assertContains(
            $originalId,
            $model->units->pluck('product_id')->map(fn ($id) => (int) $id)->all(),
            'the referenced product is not a member of the composed model'
        );
    }

    // ----------------------------------------------------------- validation

    public function test_the_payload_is_validated(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $this->postJson("/api/products/{$legacy->product_id}/convert-to-two-piece", [])
             ->assertStatus(422)
             ->assertJsonValidationErrors([
                 'indoor.model_code', 'indoor.unit_price',
                 'outdoor.model_code', 'outdoor.unit_price',
                 'keep_as',
             ]);

        $this->convert($legacy, ['keep_as' => 'sideways'])
             ->assertStatus(422)
             ->assertJsonValidationErrors('keep_as');
    }

    public function test_an_archived_product_cannot_be_converted(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);
        $legacy->delete();

        $this->convert($legacy)->assertStatus(404);
    }
}
