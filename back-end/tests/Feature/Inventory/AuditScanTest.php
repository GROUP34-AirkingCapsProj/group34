<?php

namespace Tests\Feature\Inventory;

use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Audit mode of the Inventory Operation scanner.
 *
 * The scan is read-only, but it records an activity_log row. That table was
 * never migrated, so every audit scan died with a 500 and the UI reported an
 * error the operator could do nothing about.
 */
class AuditScanTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(int $roleId, ?int $locationId = null): User
    {
        return User::create([
            'username'      => 'tester_'.uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid().'@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-'.uniqid(),
            'location_name' => 'Audit Loc',
            'location_type' => 'warehouse',
            'is_active'     => 1,
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'product_code' => 'CON-'.uniqid(),
            'product_name' => 'Test Installation material',
            'product_type' => 'installation_material',
        ]);
    }

    public function test_the_activity_log_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('activity_log'));
    }

    public function test_an_admin_audit_scan_succeeds_and_records_the_activity(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct();
        $user     = $this->makeUser(1);

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $product->product_code,
            'transaction_type' => 'audit',
            'location_id'      => $location->location_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $res->assertJsonPath('data.audit.location_id', $location->location_id);

        $this->assertSame(1, (int) DB::table('activity_log')
            ->where('user_id', $user->user_id)
            ->where('activity_type', 'audit_scan')
            ->count());
    }

    public function test_warehouse_personnel_can_audit_scan_at_their_own_location(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct();
        $user     = $this->makeUser(4, $location->location_id);

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $product->product_code,
            'transaction_type' => 'audit',
            'location_id'      => $location->location_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $res->assertJsonPath('data.audit.location_id', $location->location_id);
    }

    public function test_an_audit_scan_still_returns_its_snapshot_when_activity_logging_fails(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct();
        $user     = $this->makeUser(1);

        // A read-only scan must not 500 because a log write could not land.
        // (No DDL here: renaming the table would implicitly commit and leak the
        // rows this test's transaction is meant to roll back.)
        ActivityLog::creating(function () {
            throw new \RuntimeException('activity log unavailable');
        });

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $product->product_code,
            'transaction_type' => 'audit',
            'location_id'      => $location->location_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
    }

    protected function tearDown(): void
    {
        ActivityLog::flushEventListeners();
        parent::tearDown();
    }
}
