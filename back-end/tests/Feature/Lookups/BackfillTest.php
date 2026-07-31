<?php

namespace Tests\Feature\Lookups;

use Database\Seeders\LookupBackfill;
use Illuminate\Support\Facades\DB;

class BackfillTest extends LookupTestCase
{
    /** @test */
    public function distinct_values_are_inserted_once_with_category_when_unambiguous(): void
    {
        $category = $this->category();
        $value = $this->uniqueValue();

        $product = \App\Models\Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'A',
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
        ]);
        \App\Models\ApplianceDetail::create([
            'product_id'      => $product->product_id,
            'is_serialized'   => true,
            'capacity_rating' => $value,
        ]);

        $product2 = \App\Models\Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'B',
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
        ]);
        \App\Models\ApplianceDetail::create([
            'product_id'      => $product2->product_id,
            'is_serialized'   => true,
            'capacity_rating' => $value,
        ]);

        LookupBackfill::run();

        $rows = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$value])->get();

        $this->assertCount(1, $rows);
        $this->assertSame($category->category_id, (int) $rows->first()->category_id);
    }

    /** @test */
    public function value_spanning_two_categories_leaves_category_null(): void
    {
        $categoryA = $this->category();
        $categoryB = $this->category();
        $value = $this->uniqueValue();

        $productA = \App\Models\Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'A',
            'product_type' => 'appliance',
            'category_id'  => $categoryA->category_id,
        ]);
        \App\Models\ApplianceDetail::create([
            'product_id'      => $productA->product_id,
            'is_serialized'   => true,
            'capacity_rating' => $value,
        ]);

        $productB = \App\Models\Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'B',
            'product_type' => 'appliance',
            'category_id'  => $categoryB->category_id,
        ]);
        \App\Models\ApplianceDetail::create([
            'product_id'      => $productB->product_id,
            'is_serialized'   => true,
            'capacity_rating' => $value,
        ]);

        LookupBackfill::run();

        $row = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$value])->first();

        $this->assertNotNull($row);
        $this->assertNull($row->category_id);
    }

    /** @test */
    public function null_empty_and_whitespace_values_are_skipped(): void
    {
        $this->applianceWith(['capacity_rating' => null]);
        $this->applianceWith(['capacity_rating' => '']);
        $this->applianceWith(['capacity_rating' => '   ']);

        LookupBackfill::run();

        $this->assertFalse(
            DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [''])->exists()
        );
        $this->assertFalse(
            DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', ['   '])->exists()
        );
    }

    /** @test */
    public function case_variants_are_preserved_as_separate_rows(): void
    {
        $suffix = substr(uniqid(), -6);
        $lower = "1.5hp{$suffix}";
        $upper = "1.5HP{$suffix}";

        $this->applianceWith(['capacity_rating' => $lower]);
        $this->applianceWith(['capacity_rating' => $upper]);

        LookupBackfill::run();

        $lowerRow = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$lower])->first();
        $upperRow = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$upper])->first();

        $this->assertNotNull($lowerRow);
        $this->assertNotNull($upperRow);
        $this->assertNotSame($lowerRow->capacity_id, $upperRow->capacity_id);
    }

    /** @test */
    public function variants_are_backfilled_too(): void
    {
        $value = $this->uniqueValue() . '-variant';

        $this->applianceWith(['variant' => $value]);

        LookupBackfill::run();

        $row = DB::table('variant_lookup')->whereRaw('BINARY variant_name = ?', [$value])->first();

        $this->assertNotNull($row);
    }

    /** @test */
    public function capacity_only_on_a_product_set_row_is_still_backfilled(): void
    {
        $value = $this->uniqueValue();

        $this->productSetWith(['capacity_rating' => $value]);

        LookupBackfill::run();

        $row = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$value])->first();

        $this->assertNotNull($row);
        $this->assertNull($row->category_id);
    }

    /** @test */
    public function presets_are_backfilled_with_unit_and_conversion_factor(): void
    {
        $unit = \App\Models\UnitLookup::create([
            'unit_name'         => 'Box ' . uniqid(),
            'unit_abbreviation' => 'bx',
        ]);

        $product = \App\Models\Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Consumable',
            'product_type' => 'installation_material',
        ]);

        $label = 'Preset ' . uniqid();

        \App\Models\ProductIssuableUnit::create([
            'product_id'        => $product->product_id,
            'unit_id'           => $unit->unit_id,
            'label'             => $label,
            'conversion_factor' => 3.5,
            'is_whole_package'  => true,
            'sort_order'        => 0,
        ]);

        LookupBackfill::run();

        $row = DB::table('issuable_unit_preset')->whereRaw('BINARY label = ?', [$label])->first();

        $this->assertNotNull($row);
        $this->assertSame($unit->unit_id, (int) $row->unit_id);
        $this->assertEquals(3.5, (float) $row->default_conversion_factor);
        $this->assertSame(1, (int) $row->is_whole_package);
    }

    /** @test */
    public function running_twice_inserts_nothing_the_second_time(): void
    {
        $value = $this->uniqueValue();
        $this->applianceWith(['capacity_rating' => $value]);

        LookupBackfill::run();
        $countAfterFirst = DB::table('capacity_lookup')->count();

        LookupBackfill::run();
        $countAfterSecond = DB::table('capacity_lookup')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    /** @test */
    public function value_longer_than_destination_column_is_skipped_not_truncated(): void
    {
        // capacity_value destination is varchar(20); appliance_details.capacity_rating
        // is also varchar(20), so use product_sets (capacity_rating varchar(60)) to
        // exercise an over-length value that fits the source column but not the
        // destination.
        $overLength = str_repeat('X', 25);

        $this->productSetWith(['capacity_rating' => $overLength]);

        LookupBackfill::run();

        $row = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$overLength])->first();

        $this->assertNull($row);
    }
}
