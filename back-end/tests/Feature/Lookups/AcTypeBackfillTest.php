<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\Product;
use App\Support\AcTypeBackfill;

/**
 * The backfill resolves the aircon kind from appliance_details.variant first,
 * then falls back to products.description, where the kind has lived as prose
 * since the catalogue was seeded ("...split-type air conditioner.").
 *
 * Variant wins because the description names the refrigeration topology, not
 * the form factor: a ceiling cassette's description also says "split-type".
 *
 * A row matching no known type is left NULL on purpose. Guessing a type for it
 * would produce a confident wrong answer that nobody would ever think to
 * re-check, which is worse than an obviously empty field.
 */
class AcTypeBackfillTest extends LookupTestCase
{
    private function applianceWithDescription(?string $description, ?string $variant = null): ApplianceDetail
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
            'variant'       => $variant,
        ]);
    }

    private function assertResolvesTo(string $typeName, ApplianceDetail $detail): void
    {
        $expected = AcTypeLookup::where('ac_type_name', $typeName)->value('ac_type_id');
        $this->assertNotNull($expected, "ac type missing - seed the test database: {$typeName}");

        $this->assertSame($expected, $detail->fresh()->ac_type_id);
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

    /**
     * THE regression test for the variant pass.
     *
     * A ceiling cassette's description says "split-type air conditioner",
     * because a cassette is a split system. Reading the description alone
     * types it Split Type, which is wrong and silently so.
     */
    public function test_a_cassette_variant_beats_a_split_type_description(): void
    {
        $detail = $this->applianceWithDescription(
            'Carrier 2.5HP inverter split-type air conditioner. 5-year warranty.',
            'Ceiling Cassette Inverter'
        );

        AcTypeBackfill::run();

        $this->assertResolvesTo('Cassette', $detail);
    }

    public function test_a_floor_standing_variant_beats_a_split_type_description(): void
    {
        $detail = $this->applianceWithDescription(
            'Koppel 3HP non-inverter split-type air conditioner.',
            'Floor Standing Non-Inverter'
        );

        AcTypeBackfill::run();

        $this->assertResolvesTo('Floor Standing', $detail);
    }

    public function test_a_slim_floor_standing_variant_still_reaches_floor_standing(): void
    {
        $detail = $this->applianceWithDescription(
            'A split type air conditioner.',
            'Slim Floor Standing Inverter'
        );

        AcTypeBackfill::run();

        $this->assertResolvesTo('Floor Standing', $detail);
    }

    /** The abbreviated spelling, which omits "standing" entirely. */
    public function test_an_abbreviated_floor_inverter_variant_maps_to_floor_standing(): void
    {
        $detail = $this->applianceWithDescription(
            'A split-type air conditioner.',
            'Floor Inverter'
        );

        AcTypeBackfill::run();

        $this->assertResolvesTo('Floor Standing', $detail);
    }

    public function test_a_ceiling_mounted_variant_maps_to_ceiling_mounted(): void
    {
        $detail = $this->applianceWithDescription(
            'A split-type air conditioner.',
            'Ceiling Mounted Inverter'
        );

        AcTypeBackfill::run();

        $this->assertResolvesTo('Ceiling Mounted', $detail);
    }

    /**
     * A variant that is pure marketing says nothing about form factor, so the
     * description fallback still has to carry the row.
     */
    public function test_a_marketing_variant_falls_back_to_the_description(): void
    {
        $detail = $this->applianceWithDescription(
            'CHiQ 1.5HP inverter split-type air conditioner.',
            'Titan Gold'
        );

        AcTypeBackfill::run();

        $this->assertResolvesTo('Split Type', $detail);
    }

    public function test_running_twice_changes_nothing_for_a_variant_matched_row(): void
    {
        $detail = $this->applianceWithDescription(
            'A split-type air conditioner.',
            'Ceiling Cassette Inverter'
        );

        AcTypeBackfill::run();
        $first = $detail->fresh()->ac_type_id;

        AcTypeBackfill::run();

        $this->assertSame($first, $detail->fresh()->ac_type_id);
        $this->assertResolvesTo('Cassette', $detail);
    }
}
