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
}
