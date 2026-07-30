<?php

namespace Tests\Feature\Lookups;

use App\Services\LookupRenameService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LookupRenameServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeApplianceRow(string $capacity): int
    {
        $productId = DB::table('products')->insertGetId([
            // product_code is NOT NULL and unique; omitting it fails the insert.
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'appliance',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('appliance_details')->insert([
            'product_id'      => $productId,
            'capacity_rating' => $capacity,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $productId;
    }

    public function test_cascade_updates_every_matching_row_and_returns_the_count(): void
    {
        $this->makeApplianceRow('9XHP');
        $this->makeApplianceRow('9XHP');
        $this->makeApplianceRow('8XHP');

        $service = app(LookupRenameService::class);

        $updated = $service->cascade(
            ['appliance_details' => 'capacity_rating'],
            '9XHP',
            '9X HP'
        );

        $this->assertSame(2, $updated);
        $this->assertSame(0, DB::table('appliance_details')->where('capacity_rating', '9XHP')->count());
        $this->assertSame(2, DB::table('appliance_details')->where('capacity_rating', '9X HP')->count());
        $this->assertSame(1, DB::table('appliance_details')->where('capacity_rating', '8XHP')->count());
    }

    public function test_cascade_spans_multiple_tables(): void
    {
        $this->makeApplianceRow('7XHP');

        // set_code/set_name are omitted here even though
        // database/migrations/2026_07_24_000001_create_product_sets_tables.php
        // declares them NOT NULL. That migration is Schema::hasTable-guarded and
        // never ran against this live/test schema, whose product_sets table has
        // no such columns (see SHOW CREATE TABLE product_sets). This is schema
        // drift, not a broken test — do not "fix" the insert shape without first
        // reconciling the schema, which is out of scope here.
        DB::table('product_sets')->insert([
            'capacity_rating' => '7XHP',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $updated = app(LookupRenameService::class)->cascade(
            [
                'appliance_details' => 'capacity_rating',
                'product_sets'      => 'capacity_rating',
            ],
            '7XHP',
            '7X HP'
        );

        $this->assertSame(2, $updated, 'One appliance row + one set row');
        $this->assertSame(1, DB::table('appliance_details')->where('capacity_rating', '7X HP')->count());
        $this->assertSame(1, DB::table('product_sets')->where('capacity_rating', '7X HP')->count());
    }

    public function test_cascade_of_an_unused_value_reports_zero(): void
    {
        $updated = app(LookupRenameService::class)->cascade(
            ['appliance_details' => 'capacity_rating'],
            'NOT-IN-USE-' . uniqid(),
            'STILL-NOT-USED'
        );

        $this->assertSame(0, $updated);
    }

    public function test_cascade_is_a_no_op_when_the_name_did_not_change(): void
    {
        $this->makeApplianceRow('6XHP');

        $updated = app(LookupRenameService::class)->cascade(
            ['appliance_details' => 'capacity_rating'],
            '6XHP',
            '6XHP'
        );

        $this->assertSame(0, $updated);
        $this->assertSame(1, DB::table('appliance_details')->where('capacity_rating', '6XHP')->count());
    }

    public function test_cascade_is_case_sensitive_and_does_not_conflate_case_variants(): void
    {
        $this->makeApplianceRow('5XHP');
        $this->makeApplianceRow('5xhp');

        $updated = app(LookupRenameService::class)->cascade(
            ['appliance_details' => 'capacity_rating'],
            '5XHP',
            '5X HP'
        );

        $this->assertSame(1, $updated);
        // A plain `where` would not distinguish these two values under the
        // table's ci collation, so assert with BINARY here too.
        $this->assertSame(
            1,
            DB::table('appliance_details')->whereRaw('BINARY capacity_rating = ?', ['5X HP'])->count()
        );
        $this->assertSame(
            1,
            DB::table('appliance_details')->whereRaw('BINARY capacity_rating = ?', ['5xhp'])->count()
        );
    }

    public function test_cascade_includes_soft_deleted_rows(): void
    {
        DB::table('product_sets')->insert([
            'capacity_rating' => '4XHP',
            'created_at'      => now(),
            'updated_at'      => now(),
            'deleted_at'      => now(),
        ]);

        $updated = app(LookupRenameService::class)->cascade(
            ['product_sets' => 'capacity_rating'],
            '4XHP',
            '4X HP'
        );

        $this->assertSame(1, $updated);
        $this->assertSame(1, DB::table('product_sets')->where('capacity_rating', '4X HP')->count());
    }
}
