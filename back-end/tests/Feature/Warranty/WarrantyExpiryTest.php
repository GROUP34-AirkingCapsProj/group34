<?php

namespace Tests\Feature\Warranty;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\WarrantyRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Expiry must be true on the screen whether or not any job has ever run.
 *
 * It used to happen in a batch UPDATE inside the LIST endpoint, so a warranty
 * only expired when somebody opened that page — and every other reader
 * (customer history, exports, reports) saw a stale "Active" until they did.
 * Moving that into a scheduled job and stopping there would have been worse:
 * DEPLOYMENT.md mentions no cron, so nothing would ever have run it.
 *
 * So the state is DERIVED from the end date on read, and the stored column is
 * a cache that a command tidies up.
 */
class WarrantyExpiryTest extends TestCase
{
    use DatabaseTransactions;

    private const ACTIVE  = 33;
    private const EXPIRED = 34;
    private const CLAIMED = 35;

    private function admin(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username'      => 'warexp_' . uniqid(),
            'first_name'    => 'War',
            'last_name'     => 'Expiry',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    /** A record whose cover ended $daysAgo days ago, still stored as $statusId. */
    private function makeWarranty(int $daysAgo, int $statusId = self::ACTIVE): WarrantyRecord
    {
        $product  = Product::where('product_type', 'appliance')->firstOrFail();
        $customer = Customer::create(['customer_name' => 'Expiry Cust', 'customer_type' => 'individual']);

        return WarrantyRecord::create([
            'product_id'             => $product->product_id,
            'customer_id'            => $customer->customer_id,
            'serial_number'          => 'SN-EXP-' . strtoupper(uniqid()),
            'sale_date'              => now()->subDays($daysAgo + 365)->toDateString(),
            'warranty_start_date'    => now()->subDays($daysAgo + 365)->toDateString(),
            'warranty_end_date'      => now()->subDays($daysAgo)->toDateString(),
            'warranty_period_months' => 12,
            'status_id'              => $statusId,
        ]);
    }

    /** THE point: the stored column is stale and the answer is still right. */
    public function test_a_lapsed_warranty_reads_as_expired_without_any_job_running(): void
    {
        $warranty = $this->makeWarranty(daysAgo: 1);

        $this->assertSame(self::EXPIRED, (int) $warranty->effective_status_id);
        $this->assertSame(self::ACTIVE, (int) $warranty->fresh()->status_id,
            'the stored column is untouched — only the reading is derived');
    }

    public function test_a_live_warranty_reads_as_active(): void
    {
        $warranty = $this->makeWarranty(daysAgo: -30); // ends in 30 days

        $this->assertSame(self::ACTIVE, (int) $warranty->effective_status_id);
    }

    /** Claimed is a real stored state no date can imply, and it outranks expiry. */
    public function test_a_claimed_warranty_never_becomes_expired(): void
    {
        $warranty = $this->makeWarranty(daysAgo: 400, statusId: self::CLAIMED);

        $this->assertSame(self::CLAIMED, (int) $warranty->effective_status_id);
    }

    /** Filtering has to agree with the badge, or the list lies about itself. */
    public function test_the_active_filter_excludes_a_lapsed_warranty(): void
    {
        $lapsed = $this->makeWarranty(daysAgo: 1);

        $ids = WarrantyRecord::withEffectiveStatus(self::ACTIVE)->pluck('warranty_id')->all();

        $this->assertNotContains((int) $lapsed->warranty_id, array_map('intval', $ids));
    }

    public function test_the_expired_filter_includes_a_lapsed_warranty_still_stored_active(): void
    {
        $lapsed = $this->makeWarranty(daysAgo: 1);

        $ids = WarrantyRecord::withEffectiveStatus(self::EXPIRED)->pluck('warranty_id')->all();

        $this->assertContains((int) $lapsed->warranty_id, array_map('intval', $ids));
    }

    /** A GET must not write. That is the whole complaint about the old code. */
    public function test_listing_warranties_does_not_modify_stored_rows(): void
    {
        $lapsed = $this->makeWarranty(daysAgo: 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/warranties')
            ->assertOk();

        $this->assertSame(self::ACTIVE, (int) $lapsed->fresh()->status_id,
            'the list endpoint must leave the database alone');
    }

    /** The API reports the derived state, not the stale column. */
    public function test_the_api_reports_the_derived_state(): void
    {
        $lapsed = $this->makeWarranty(daysAgo: 3);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/warranties/{$lapsed->warranty_id}")
            ->assertOk()
            ->assertJsonPath('data.status_id', self::EXPIRED)
            ->assertJsonPath('data.is_expired', true);
    }

    /**
     * Customer history has to agree with the warranty page.
     *
     * It used to read status_name straight off the stored column while
     * reporting is_expired from the date in the same payload, so one response
     * could say "Active" and "is_expired: true" about the same unit.
     */
    public function test_customer_history_reports_the_derived_state(): void
    {
        $lapsed = $this->makeWarranty(daysAgo: 7);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/customers/{$lapsed->customer_id}/history")
            ->assertOk();

        $row = collect($response->json('data.warranty_records'))
            ->firstWhere('warranty_id', $lapsed->warranty_id);

        $this->assertNotNull($row, 'the lapsed warranty should still be listed');
        $this->assertSame('Expired', $row['status_name']);
        $this->assertTrue($row['is_expired'], 'and it must not contradict itself');
    }

    /** …and the command is what actually tidies the column. */
    public function test_the_expire_command_updates_the_stored_column(): void
    {
        $lapsed = $this->makeWarranty(daysAgo: 2);

        $this->artisan('warranties:expire')->assertExitCode(0);

        $this->assertSame(self::EXPIRED, (int) $lapsed->fresh()->status_id);
    }

    public function test_the_expire_command_is_safe_to_run_twice(): void
    {
        $lapsed = $this->makeWarranty(daysAgo: 2);

        $this->artisan('warranties:expire')->assertExitCode(0);
        $this->artisan('warranties:expire')->assertExitCode(0);

        $this->assertSame(self::EXPIRED, (int) $lapsed->fresh()->status_id);
    }

    public function test_the_expire_command_leaves_live_and_claimed_records_alone(): void
    {
        $live    = $this->makeWarranty(daysAgo: -10);
        $claimed = $this->makeWarranty(daysAgo: 400, statusId: self::CLAIMED);

        $this->artisan('warranties:expire')->assertExitCode(0);

        $this->assertSame(self::ACTIVE, (int) $live->fresh()->status_id);
        $this->assertSame(self::CLAIMED, (int) $claimed->fresh()->status_id);
    }
}
