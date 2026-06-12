<?php

namespace Tests\Feature\Permissions;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scoped_user_dashboard_counts_only_their_location(): void
    {
        $uid = uniqid();

        $locA = (int) DB::table('locations')->insertGetId([
            'location_name' => 'DashLocA_' . $uid,
            'location_code' => 'DSH-A-' . $uid,
            'location_type' => 'branch_office',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $locB = (int) DB::table('locations')->insertGetId([
            'location_name' => 'DashLocB_' . $uid,
            'location_code' => 'DSH-B-' . $uid,
            'location_type' => 'branch_office',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $product = Product::first();
        $this->assertNotNull($product, 'Seed at least one product before running this test.');

        // One inventory row at locA, one at locB — global total_sales would be 2 if Sale::count() is used
        // We use Inventory rows as the observable count (total_items in stats = Product::count, not per-location)
        // The most cleanly location-scoped field we can assert is total_sales (Sale::forUser)
        // But Sales rows are harder to create in isolation. Instead we seed Inventory rows and
        // assert via a separate endpoint that uses Inventory::forUser — or we assert total_sales is
        // not mixing locations.
        //
        // For simplicity: create two Inventory rows (one per location), then assert that the
        // stats endpoint returns successfully for a scoped role-4 user.
        // We also assert total_sales does NOT include a sale seeded for locB only.
        Inventory::create([
            'product_id'       => $product->product_id,
            'location_id'      => $locA,
            'quantity_on_hand' => 10,
        ]);
        Inventory::create([
            'product_id'       => $product->product_id,
            'location_id'      => $locB,
            'quantity_on_hand' => 20,
        ]);

        // Create a Sale for locB only — a scoped locA user should NOT count it
        $paymentStatusId = DB::table('status_lookup')
            ->where('status_category', 'sale')
            ->value('status_id');

        DB::table('sales')->insertGetId([
            'invoice_number'    => 'INV-B-' . $uid,
            'location_id'       => $locB,
            'total_amount'      => 100,
            'payment_status_id' => $paymentStatusId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Create a Sale for locA — the scoped user SHOULD see this one
        DB::table('sales')->insertGetId([
            'invoice_number'    => 'INV-A-' . $uid,
            'location_id'       => $locA,
            'total_amount'      => 50,
            'payment_status_id' => $paymentStatusId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Count how many sales exist at locA BEFORE our user makes the request,
        // so we can assert the exact returned count
        $expectedSalesCount = (int) DB::table('sales')->where('location_id', $locA)->count();

        $wp = User::create([
            'username'      => 'db_' . uniqid(),
            'first_name'    => 'D',
            'last_name'     => 'B',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => 4,
            'location_id'   => $locA,
        ]);

        $this->actingAs($wp, 'sanctum');

        $res = $this->getJson('/api/dashboard/stats');
        $res->assertOk();

        $data = $res->json('data');
        $returnedSales = (int) data_get($data, 'total_sales');

        // The scoped user should see only locA sales — NOT the combined global count
        $globalSalesCount = (int) DB::table('sales')->count();
        $this->assertSame($expectedSalesCount, $returnedSales,
            "total_sales should be scoped to locA ({$expectedSalesCount}), not global ({$globalSalesCount})."
        );

        // Also assert locB's sale is excluded
        $this->assertLessThan($globalSalesCount, $returnedSales,
            'total_sales returned global count instead of scoped count.'
        );
    }
}
