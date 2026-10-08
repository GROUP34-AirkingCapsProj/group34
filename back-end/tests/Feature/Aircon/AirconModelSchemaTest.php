<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\AirconModelUnit;
use Illuminate\Database\QueryException;

/**
 * The storage guarantees an aircon model rests on: that it groups unit lines,
 * that a product cannot be double-listed, that lines die with their model, and
 * that archiving a member product does not silently zero the model's price.
 */
class AirconModelSchemaTest extends AirconTestCase
{
    private function model(array $overrides = []): AirconModel
    {
        return AirconModel::create(array_merge([
            'model_name'      => 'Model ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
        ], $overrides));
    }

    public function test_a_model_holds_indoor_and_outdoor_unit_lines(): void
    {
        $model = $this->model();

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('indoor')->product_id,
            'quantity'        => 1,
            'sort_order'      => 0,
        ]);
        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('outdoor')->product_id,
            'quantity'        => 1,
            'sort_order'      => 1,
        ]);

        $units = $model->fresh()->units;

        $this->assertCount(2, $units);
        $this->assertSame(
            ['indoor', 'outdoor'],
            $units->map(fn (AirconModelUnit $u) => $u->unitRole())->all(),
            'units must come back in sort_order, carrying the role from their product'
        );
    }

    public function test_the_same_product_cannot_be_added_to_one_model_twice(): void
    {
        $model   = $this->model();
        $product = $this->unitProduct('indoor');

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $product->product_id,
            'quantity'        => 1,
        ]);

        $this->expectException(QueryException::class);

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $product->product_id,
            'quantity'        => 1,
        ]);
    }

    public function test_deleting_a_model_removes_its_unit_lines(): void
    {
        $model = $this->model();

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('indoor')->product_id,
            'quantity'        => 1,
        ]);

        $modelId = $model->aircon_model_id;
        $model->forceDelete();

        $this->assertSame(0, AirconModelUnit::where('aircon_model_id', $modelId)->count());
    }

    public function test_an_archived_member_product_still_resolves(): void
    {
        $model   = $this->model();
        $product = $this->unitProduct('indoor', 12345.00);

        $line = AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $product->product_id,
            'quantity'        => 1,
        ]);

        $product->delete(); // soft delete

        $this->assertNotNull(
            $line->fresh()->product,
            'an archived product must still resolve, or the line renders nameless'
        );
        $this->assertSame('12345.00', (string) $line->fresh()->product->unit_price);
    }

    public function test_the_label_always_carries_the_model_name(): void
    {
        // Only model_name is NOT NULL, so this is a legal row. Every other
        // label component is absent — if the name were filtered out with them
        // the label would be an empty string and render as a blank row.
        $model = AirconModel::create([
            'model_name'   => 'Bare Model ' . uniqid(),
            'is_two_piece' => true,
        ]);

        $this->assertSame($model->model_name, $model->fresh()->label());
    }

    public function test_the_label_joins_every_populated_component(): void
    {
        $brand = $this->brand();

        $model = $this->model([
            'model_name' => 'Full Model',
            'brand_id'   => $brand->brand_id,
        ]);

        $this->assertSame(
            "Full Model · {$brand->brand_name} · 2HP · Inverter · Split Type",
            $model->fresh()->label()
        );
    }

    public function test_total_price_sums_quantity_times_member_price(): void
    {
        $model = $this->model();

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('indoor', 5000.00)->product_id,
            'quantity'        => 3,
        ]);
        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('outdoor', 20000.00)->product_id,
            'quantity'        => 1,
        ]);

        $this->assertSame(35000.00, $model->fresh()->totalPrice());
    }

    public function test_an_archived_member_still_counts_toward_the_total(): void
    {
        $model   = $this->model();
        $product = $this->unitProduct('outdoor', 8000.00);

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $product->product_id,
            'quantity'        => 1,
        ]);

        $product->delete();

        $this->assertSame(
            8000.00,
            $model->fresh()->totalPrice(),
            'withTrashed() on the line relation is what stops an archived member pricing at zero'
        );
    }
}
