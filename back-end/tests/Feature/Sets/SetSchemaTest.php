<?php

namespace Tests\Feature\Sets;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetSchemaTest extends SetTestCase
{
    /** Insert a set row directly; the Eloquent models do not exist until Task 2. */
    private function insertSet(string $code, string $name = 'BOQ'): int
    {
        return (int) DB::table('product_sets')->insertGetId([
            'set_code'   => $code,
            'set_name'   => $name,
            'is_active'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'set_id');
    }

    public function test_product_set_tables_exist_with_matching_columns(): void
    {
        $this->assertTrue(Schema::hasTable('product_sets'));
        $this->assertTrue(Schema::hasTable('product_set_lines'));

        foreach (['set_code', 'set_name', 'brand_id', 'capacity_rating', 'variant', 'is_active', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_sets', $col), "product_sets missing {$col}");
        }
        foreach (['set_id', 'product_id', 'quantity', 'issuable_unit_id', 'spec_note', 'sort_order'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_set_lines', $col), "product_set_lines missing {$col}");
        }
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

    public function test_set_code_is_unique(): void
    {
        $code = 'DUPE-' . uniqid();
        $this->insertSet($code);

        $this->expectException(QueryException::class);
        $this->insertSet($code, 'Second set, same code');
    }

    public function test_deleting_a_set_cascades_to_its_lines(): void
    {
        $setId   = $this->insertSet('CASCADE-' . uniqid());
        $product = $this->makeConsumable();

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
