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

    /**
     * Every relationship column added by Phase 2 must carry a real constraint,
     * not just an index. issuance_details.batch_id shipped with an index and no
     * foreign key, and nothing caught it — this asserts the whole set so the
     * next omission fails loudly.
     */
    public function test_every_phase_two_relationship_column_has_a_foreign_key(): void
    {
        // Schema::getForeignKeys() rather than information_schema: MySQL's
        // REFERENCED_TABLE_NAME column and DATABASE() are both MySQL-only.
        $interesting = [
            'issuance_sets' => null,        // every FK on the table
            'pick_reservations' => null,    // every FK on the table
            'issuance_details' => ['issuance_set_id', 'batch_id'],
        ];

        $actual = collect($interesting)
            ->flatMap(function ($columns, $table) {
                return collect(Schema::getForeignKeys($table))
                    ->filter(fn ($fk) => $columns === null || in_array($fk['columns'][0], $columns, true))
                    ->mapWithKeys(fn ($fk) => ["{$table}.{$fk['columns'][0]}" => $fk['foreign_table']]);
            });

        $expected = [
            'issuance_details.issuance_set_id' => 'issuance_sets',
            'issuance_details.batch_id'        => 'inventory_batches',
            'issuance_sets.issuance_id'        => 'issuances',
            'issuance_sets.set_id'             => 'product_sets',
            'pick_reservations.product_id'     => 'products',
            'pick_reservations.location_id'    => 'locations',
            'pick_reservations.batch_id'       => 'inventory_batches',
            'pick_reservations.serial_id'      => 'item_serial',
        ];

        foreach ($expected as $column => $referencedTable) {
            $this->assertSame(
                $referencedTable,
                $actual[$column] ?? null,
                "{$column} must have a foreign key referencing {$referencedTable}"
            );
        }
    }

    public function test_deleting_issuance_cascades_away_its_issuance_sets(): void
    {
        $location = $this->makeLocation();
        $set = DB::table('product_sets')->insertGetId([
            'capacity_rating' => '2HP',
            'variant'         => 'non-inverter',
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
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
            'capacity_rating' => '2HP',
            'variant'         => 'non-inverter',
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
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
