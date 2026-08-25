<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\Product;
use App\Support\AcTypeBackfill;

/**
 * The backfill reads products.description, where the aircon kind has lived as
 * prose since the catalogue was seeded ("...split-type air conditioner.").
 *
 * A description matching no known type is left NULL on purpose. Guessing a
 * type for it would produce a confident wrong answer that nobody would ever
 * think to re-check, which is worse than an obviously empty field.
 */
class AcTypeBackfillTest extends LookupTestCase
{
    private function applianceWithDescription(?string $description): ApplianceDetail
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Backfill Test ' . uniqid(),
            'product_type' => 'appliance',
            'description'  => $description,
        ]);

        return ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'is_serialized' => true,
        ]);
    }

    public function test_a_split_type_description_maps_to_split_type(): void
    {
        $detail = $this->applianceWithDescription('CHiQ Morandi Inverter 1HP split-type air conditioner. 3-year warranty.');

        AcTypeBackfill::run();

        $expected = AcTypeLookup::where('ac_type_name', 'Split Type')->value('ac_type_id');

        $this->assertSame($expected, $detail->fresh()->ac_type_id);
    }

    public function test_a_window_type_description_maps_to_window_type(): void
    {
        $detail = $this->applianceWithDescription('TCL Window Non-Inverter 0.6HP window-type air conditioner. 3-year warranty.');

        AcTypeBackfill::run();

        $expected = AcTypeLookup::where('ac_type_name', 'Window Type')->value('ac_type_id');

        $this->assertSame($expected, $detail->fresh()->ac_type_id);
    }

    public function test_an_unrecognised_description_is_left_null(): void
    {
        $detail = $this->applianceWithDescription('55-inch 4K smart television.');

        AcTypeBackfill::run();

        $this->assertNull($detail->fresh()->ac_type_id);
    }

    public function test_a_null_description_is_left_null(): void
    {
        $detail = $this->applianceWithDescription(null);

        AcTypeBackfill::run();

        $this->assertNull($detail->fresh()->ac_type_id);
    }

    public function test_every_appliance_gets_unit_role_complete(): void
    {
        $split = $this->applianceWithDescription('a split-type air conditioner');
        $tv    = $this->applianceWithDescription('55-inch 4K smart television.');

        AcTypeBackfill::run();

        $this->assertSame('complete', $split->fresh()->unit_role);
        $this->assertSame('complete', $tv->fresh()->unit_role);
    }

    public function test_an_already_set_unit_role_is_not_overwritten(): void
    {
        $detail = $this->applianceWithDescription('a split-type air conditioner');
        $detail->update(['unit_role' => 'indoor']);

        AcTypeBackfill::run();

        $this->assertSame('indoor', $detail->fresh()->unit_role);
    }

    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $detail = $this->applianceWithDescription('a split-type air conditioner');

        AcTypeBackfill::run();
        $first = $detail->fresh()->ac_type_id;

        AcTypeBackfill::run();

        $this->assertSame($first, $detail->fresh()->ac_type_id);
    }
}
