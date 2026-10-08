<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\ApplianceDetail;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;

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
    private function legacyProduct(float $price = 50000.00, float $cost = 40000.00): Product
    {
        $product = Product::create([
            'product_code'       => 'LEG-' . uniqid(),
            'product_name'       => 'Legacy Aircon ' . uniqid(),
            'product_type'       => 'appliance',
            'description'        => 'A whole aircon on one row.',
            'unit_price'         => $price,
            'cost_price'         => $cost,
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

        $installationMaterial = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Copper Tubing ' . uniqid(),
            'product_type' => 'installation_material',
            'unit_price'   => 50000.00,
        ]);

        $this->convert($installationMaterial)
             ->assertStatus(422)
             ->assertJsonValidationErrors('product');

        $this->assertSame(0, ApplianceDetail::where('product_id', $installationMaterial->product_id)->count());
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

    /**
     * The default model name must not be byte-identical to the product name:
     * the original product survives the conversion under its own name, so the
     * two would sit side by side in the same UI reading the same.
     */
    public function test_the_default_model_name_is_distinguishable_from_the_product(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $response = $this->convert($legacy);
        $response->assertStatus(201);

        $modelName = $response->json('data.model_name');

        $this->assertNotSame($legacy->product_name, $modelName);
        $this->assertStringContainsString($legacy->product_name, $modelName);
        $this->assertStringContainsString('2HP', $modelName);
        $this->assertStringContainsString('Inverter', $modelName);
    }

    public function test_an_explicit_model_name_is_used_verbatim(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $this->convert($legacy, ['model_name' => 'Chosen Name'])
             ->assertStatus(201)
             ->assertJsonPath('data.model_name', 'Chosen Name');
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

        // 2. That id still resolves to a product at all. This is the
        //    assertion that catches an archived original: the BelongsTo has
        //    no withTrashed(), so a soft-deleted product reads back as null
        //    here and the test stops on this line. (The trashed() checks
        //    below are therefore belt-and-braces — they only run once the
        //    product has already been shown to be live.)
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

    // ---------------------------------------------------------- the cost

    /**
     * products.cost_price is NOT NULL DEFAULT 0.00, so a partner created
     * without one is not left undecided — it is priced at a hard zero, and
     * nothing downstream ever repairs it. Stock valuation, the inventory
     * summary's unit cost, the dashboard's margin and warranty-claim
     * valuation all read that column directly.
     */
    public function test_the_cost_is_split_by_the_same_ratio_as_the_price(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00, 40000.00);

        $response = $this->convert($legacy, [
            'keep_as' => 'indoor',
            'indoor'  => ['model_code' => 'IDU-100', 'unit_price' => 20000.00],
            'outdoor' => ['model_code' => 'ODU-100', 'unit_price' => 30000.00],
        ]);
        $response->assertStatus(201);

        $partnerId = (int) collect($response->json('data.units'))
            ->firstWhere('unit_role', 'outdoor')['product_id'];

        $indoorCost  = (float) Product::find($legacy->product_id)->cost_price;
        $outdoorCost = (float) Product::find($partnerId)->cost_price;

        // 20000/50000 of 40000 = 16000; the remainder is 24000.
        $this->assertSame(16000.00, $indoorCost);
        $this->assertSame(24000.00, $outdoorCost);

        // Neither side is the silent zero.
        $this->assertGreaterThan(0, $indoorCost, 'the indoor side was left costing nothing');
        $this->assertGreaterThan(0, $outdoorCost, 'the outdoor side was left costing nothing');

        // And together they are still what the single row cost — the
        // original's own cost had to come down, or the pair double-counts it.
        $this->assertSame(40000.00, round($indoorCost + $outdoorCost, 2));
    }

    public function test_an_uneven_cost_split_still_sums_to_the_original(): void
    {
        $this->actingAsAdmin();

        // 33333.33/50000 of 40000.55 does not land on a whole centavo. The
        // second side is taken as the remainder for exactly this case.
        $legacy = $this->legacyProduct(50000.00, 40000.55);

        $response = $this->convert($legacy, [
            'indoor'  => ['model_code' => 'IDU-100', 'unit_price' => 33333.33],
            'outdoor' => ['model_code' => 'ODU-100', 'unit_price' => 16666.67],
        ]);
        $response->assertStatus(201);

        $partnerId = (int) collect($response->json('data.units'))
            ->firstWhere('unit_role', 'outdoor')['product_id'];

        $indoorCost  = (float) Product::find($legacy->product_id)->cost_price;
        $outdoorCost = (float) Product::find($partnerId)->cost_price;

        $this->assertSame(40000.55, round($indoorCost + $outdoorCost, 2));
        $this->assertGreaterThan(0, $indoorCost);
        $this->assertGreaterThan(0, $outdoorCost);
    }

    public function test_a_zero_priced_original_splits_its_cost_evenly(): void
    {
        $this->actingAsAdmin();

        // No price ratio to read: both declared prices must be zero to pass
        // the price guard, so there is nothing to divide the cost by.
        $legacy = $this->legacyProduct(0.00, 1000.00);

        $this->convert($legacy, [
            'indoor'  => ['model_code' => 'IDU-100', 'unit_price' => 0],
            'outdoor' => ['model_code' => 'ODU-100', 'unit_price' => 0],
        ])->assertStatus(201);

        $partner = Product::where('product_code', 'like', $legacy->product_code . '-%')->firstOrFail();

        $this->assertSame(500.00, (float) $legacy->fresh()->cost_price);
        $this->assertSame(500.00, (float) $partner->cost_price);
    }

    public function test_a_zero_cost_original_stays_at_zero_on_both_sides(): void
    {
        $this->actingAsAdmin();

        // Nothing to split. The guard is about not INVENTING a zero, not
        // about refusing one the catalogue already recorded.
        $legacy = $this->legacyProduct(50000.00, 0.00);

        $this->convert($legacy)->assertStatus(201);

        $partner = Product::where('product_code', 'like', $legacy->product_code . '-%')->firstOrFail();

        $this->assertSame(0.00, (float) $legacy->fresh()->cost_price);
        $this->assertSame(0.00, (float) $partner->cost_price);
    }

    /**
     * A side declared at a price of zero takes a zero cost with it.
     *
     * Pinning this deliberately rather than flooring it. It looks like the
     * defect the cost split was written to fix, and it is not: that zero was
     * one nobody asked for, written because the column defaults to 0.00 when
     * omitted. This one is the ratio the user typed. A shop that prices the
     * whole set on the compressor and the indoor head at nothing has said
     * what the indoor head is worth, and inventing a cost it did not declare
     * would be the same class of mistake in the other direction.
     *
     * It is milder than the original defect too: the price is zero as well,
     * so no false margin is reported against it.
     */
    public function test_a_side_declared_at_zero_takes_a_zero_cost_with_it(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00, 40000.00);

        $this->convert($legacy, [
            'keep_as' => 'indoor',
            'indoor'  => ['model_code' => 'IDU-100', 'unit_price' => 0],
            'outdoor' => ['model_code' => 'ODU-100', 'unit_price' => 50000.00],
        ])->assertStatus(201);

        $partner = Product::where('product_code', 'like', $legacy->product_code . '-%')->firstOrFail();

        $legacy->refresh();

        // Zero price in, zero cost out - and the whole cost lands on the side
        // that was declared to carry the value.
        $this->assertSame(0.00, (float) $legacy->cost_price);
        $this->assertSame(0.00, (float) $legacy->unit_price);
        $this->assertSame(40000.00, (float) $partner->cost_price);

        // The pair still totals what the single row cost.
        $this->assertSame(40000.00, round((float) $legacy->cost_price + (float) $partner->cost_price, 2));
    }

    // ------------------------------------------------- existing membership

    /**
     * A product already composed into a model cannot be split.
     *
     * The piece-count rule reads unit_role live out of appliance_details and
     * only runs on write, so flipping this product's role would leave the
     * existing model permanently illegal with nothing to notice — and the
     * next person to edit that model would get a 422 they did not cause.
     */
    public function test_a_product_already_in_a_model_cannot_be_converted(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $model = AirconModel::create([
            'model_name'   => 'Existing One-Piece ' . uniqid(),
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => false,
        ]);
        $model->units()->create([
            'product_id' => $legacy->product_id,
            'quantity'   => 1,
            'sort_order' => 0,
        ]);

        $response = $this->convert($legacy);

        $response->assertStatus(422)->assertJsonValidationErrors('product');
        $this->assertStringContainsString($model->model_name, $response->json('errors.product.0'));

        // Nothing moved: the role the existing model depends on is intact,
        // and no partner was created.
        $this->assertSame('complete', ApplianceDetail::where('product_id', $legacy->product_id)->value('unit_role'));
        $this->assertSame(50000.00, (float) $legacy->fresh()->unit_price);
        $this->assertSame(0, Product::where('product_code', 'like', $legacy->product_code . '-%')->count());
        $this->assertSame(1, $model->units()->count());
    }

    public function test_membership_of_an_archived_model_still_blocks_the_conversion(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyProduct(50000.00);

        $model = AirconModel::create([
            'model_name'   => 'Archived One-Piece ' . uniqid(),
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => false,
        ]);
        $model->units()->create([
            'product_id' => $legacy->product_id,
            'quantity'   => 1,
            'sort_order' => 0,
        ]);
        // Archiving leaves the unit lines in place, and restore() revalidates
        // nothing — so this is a broken composition waiting to come back.
        $model->delete();

        $response = $this->convert($legacy);

        $response->assertStatus(422)->assertJsonValidationErrors('product');
        $this->assertStringContainsString('(archived)', $response->json('errors.product.0'));
    }

    // ---------------------------------------------------------- permission

    public function test_a_user_without_the_permission_is_refused(): void
    {
        // The route is gated on aircon_model_management,create. Every other
        // test here runs as an admin, so without this one the gate is
        // asserted by the route file alone.
        $roleId = Role::where('role_name', 'inventory_analyst')->value('role_id');
        $this->assertNotNull($roleId, 'role missing - seed the test database: inventory_analyst');

        $legacy = $this->legacyProduct(50000.00);

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Analyst',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));

        $this->convert($legacy)->assertStatus(403);

        $this->assertSame('complete', ApplianceDetail::where('product_id', $legacy->product_id)->value('unit_role'));
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
