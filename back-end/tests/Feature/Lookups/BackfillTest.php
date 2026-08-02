<?php

namespace Tests\Feature\Lookups;

use Database\Seeders\LookupBackfill;
use Illuminate\Support\Facades\DB;

class BackfillTest extends LookupTestCase
{
    /** @test */
    public function a_value_used_by_two_products_is_inserted_once(): void
    {
        $value = $this->uniqueValue();

        $this->applianceWith(['capacity_rating' => $value]);
        $this->applianceWith(['capacity_rating' => $value]);

        LookupBackfill::run();

        $rows = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$value])->get();

        $this->assertCount(1, $rows);
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

        // Positive control: a second, 20-character value from the same source
        // must land. Without this, the test would still pass if the entire
        // product_sets source were deleted, since it only ever asserted
        // absence.
        $suffix = substr(uniqid(), -5);
        $validLength = str_repeat('Y', 15) . $suffix; // 20 chars total

        $this->productSetWith(['capacity_rating' => $overLength]);
        $this->productSetWith(['capacity_rating' => $validLength]);

        LookupBackfill::run();

        $overLengthRow = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$overLength])->first();
        $validLengthRow = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$validLength])->first();

        $this->assertNull($overLengthRow);
        $this->assertNotNull($validLengthRow);
    }

    /** @test */
    public function value_present_in_both_appliance_and_set_sources_yields_one_row(): void
    {
        $value = $this->uniqueValue();

        $this->applianceWith(['capacity_rating' => $value]);
        // The same exact value also named on a set.
        $this->productSetWith(['capacity_rating' => $value]);

        LookupBackfill::run();

        $rows = DB::table('capacity_lookup')->whereRaw('BINARY capacity_value = ?', [$value])->get();

        $this->assertCount(1, $rows, 'a value seen in both sources must produce exactly one row');
    }
}
