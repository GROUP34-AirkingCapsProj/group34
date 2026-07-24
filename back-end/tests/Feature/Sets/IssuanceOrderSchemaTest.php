<?php

namespace Tests\Feature\Sets;

use App\Support\IssuanceStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IssuanceOrderSchemaTest extends SetTestCase
{
    public function test_new_order_columns_and_tables_exist(): void
    {
        foreach (['issuance_set_id', 'cost_price_at_issue', 'batch_id', 'zone_label', 'quantity_requested', 'quantity_short'] as $col) {
            $this->assertTrue(Schema::hasColumn('issuance_details', $col), "issuance_details missing {$col}");
        }

        $this->assertTrue(Schema::hasTable('issuance_sets'));
        $this->assertTrue(Schema::hasTable('pick_reservations'));
    }

    public function test_draft_status_exists_and_is_distinct_from_pending(): void
    {
        $this->assertNotSame(IssuanceStatus::draft(), IssuanceStatus::pending());
        $this->assertSame(30, IssuanceStatus::pending());
    }

    public function test_deleting_issuance_cascades_away_its_issuance_sets(): void
    {
        $location = $this->makeLocation();
        $set = DB::table('product_sets')->insertGetId([
            'set_code'   => 'SET-' . uniqid(),
            'set_name'   => 'Test Set',
            'is_active'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'set_id');

        $issuanceId = DB::table('issuances')->insertGetId([
            'location_id'      => $location->location_id,
            'status_id'        => IssuanceStatus::pending(),
            'discount_percent' => 0,
            'subtotal'         => 0,
            'discount_amount'  => 0,
            'total_amount'     => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], 'issuance_id');

        $issuanceSetId = DB::table('issuance_sets')->insertGetId([
            'issuance_id'        => $issuanceId,
            'set_id'             => $set,
            'set_quantity'       => 1,
            'set_name_snapshot'  => 'Test Set',
            'was_modified'       => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'issuance_set_id');

        $this->assertDatabaseHas('issuance_sets', ['issuance_set_id' => $issuanceSetId]);

        DB::table('issuances')->where('issuance_id', $issuanceId)->delete();

        $this->assertDatabaseMissing('issuance_sets', ['issuance_set_id' => $issuanceSetId]);
    }

    public function test_deleting_issuance_set_cascades_away_its_issuance_details(): void
    {
        $location = $this->makeLocation();
        $product = $this->makeAppliance();

        $set = DB::table('product_sets')->insertGetId([
            'set_code'   => 'SET-' . uniqid(),
            'set_name'   => 'Test Set',
            'is_active'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'set_id');

        $issuanceId = DB::table('issuances')->insertGetId([
            'location_id'      => $location->location_id,
            'status_id'        => IssuanceStatus::pending(),
            'discount_percent' => 0,
            'subtotal'         => 0,
            'discount_amount'  => 0,
            'total_amount'     => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], 'issuance_id');

        $issuanceSetId = DB::table('issuance_sets')->insertGetId([
            'issuance_id'        => $issuanceId,
            'set_id'             => $set,
            'set_quantity'       => 1,
            'set_name_snapshot'  => 'Test Set',
            'was_modified'       => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'issuance_set_id');

        $detailId = DB::table('issuance_details')->insertGetId([
            'issuance_id'        => $issuanceId,
            'issuance_set_id'    => $issuanceSetId,
            'product_id'         => $product->product_id,
            'unit_price'         => 100,
            'quantity_issued'    => 1,
            'quantity_in_pieces' => 1,
            'unit_price_per_piece' => 100,
            'subtotal'           => 100,
            'quantity_returned'  => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'issuance_detail_id');

        $this->assertDatabaseHas('issuance_details', ['issuance_detail_id' => $detailId]);

        DB::table('issuance_sets')->where('issuance_set_id', $issuanceSetId)->delete();

        $this->assertDatabaseMissing('issuance_details', ['issuance_detail_id' => $detailId]);
    }
}
