<?php

namespace Tests\Feature\Sets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetSchemaTest extends SetTestCase
{
    public function test_product_set_tables_have_the_multibrand_shape(): void
    {
        $this->assertTrue(Schema::hasTable('product_sets'));
        $this->assertTrue(Schema::hasTable('product_set_lines'));
        $this->assertTrue(Schema::hasTable('product_set_brands'));

        // A set now carries only a capacity + type; identity comes from its brands.
        foreach (['capacity_rating', 'variant', 'is_active', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_sets', $col), "product_sets missing {$col}");
        }
        foreach (['set_code', 'set_name', 'brand_id'] as $col) {
            $this->assertFalse(Schema::hasColumn('product_sets', $col), "product_sets must not have {$col}");
        }

        // Brands are a many-to-many pivot now.
        foreach (['set_id', 'brand_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_set_brands', $col), "product_set_brands missing {$col}");
        }

        // Lines keep their columns except issuable_unit_id, which is dropped.
        foreach (['set_id', 'product_id', 'quantity', 'spec_note', 'sort_order'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_set_lines', $col), "product_set_lines missing {$col}");
        }
        $this->assertFalse(
            Schema::hasColumn('product_set_lines', 'issuable_unit_id'),
            'product_set_lines must not have issuable_unit_id'
        );
    }

    /**
     * variant is compared for equality against appliance_details.variant when
     * suggesting a set for an aircon. If this column is ever narrowed, a long
     * variant label truncates on insert and the match silently stops working.
     */
    public function test_variant_is_wide_enough_to_hold_any_appliance_variant(): void
    {
        $width = fn (string $table) => (int) DB::selectOne(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'variant'",
            [$table]
        )->len;

        $this->assertGreaterThanOrEqual(
            $width('appliance_details'),
            $width('product_sets'),
            'product_sets.variant must be at least as wide as appliance_details.variant'
        );
    }

    public function test_deleting_a_set_cascades_to_its_lines(): void
    {
        $setId = (int) DB::table('product_sets')->insertGetId([
            'is_active'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'set_id');

        $product = $this->makeInstallationMaterial();

        DB::table('product_set_lines')->insert([
            'set_id'     => $setId,
            'product_id' => $product->product_id,
            'quantity'   => 2,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('product_set_lines')->where('set_id', $setId)->count());

        DB::table('product_sets')->where('set_id', $setId)->delete();

        $this->assertSame(
            0,
            DB::table('product_set_lines')->where('set_id', $setId)->count(),
            'lines must not outlive the set they belong to'
        );
    }
}
