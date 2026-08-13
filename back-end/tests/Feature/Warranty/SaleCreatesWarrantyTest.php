<?php

namespace Tests\Feature\Warranty;

use App\Models\ApplianceDetail;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Models\WarrantyRecord;
use App\Services\InventoryBatchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A sale is the ONLY thing that creates warranty data, and until now nothing
 * proved it does.
 *
 * Driven over HTTP, the way a salesperson drives it, because the risk is in the
 * wiring -- routes, permissions, the issuance status transition -- and not in
 * the date arithmetic. Calling the controller method directly would pass while
 * the flow the shop actually uses stayed broken.
 */
class SaleCreatesWarrantyTest extends TestCase
{
    use DatabaseTransactions;

    private const WARRANTY_ACTIVE  = 33;
    private const SERIAL_IN_STOCK  = 26;
    private const ISSUANCE_PENDING = 30;

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    /** An appliance carrying $months of cover, or a consumable when $months is null. */
    private function makeProduct(?int $months = 36): Product
    {
        $product = Product::create([
            'product_code' => ($months === null ? 'CON-' : 'APP-') . uniqid(),
            'product_name' => $months === null ? 'Test Consumable' : 'Test Appliance',
            'product_type' => $months === null ? 'installation_material' : 'appliance',
            'unit_price'   => 100,
        ]);

        if ($months !== null) {
            ApplianceDetail::create([
                'product_id'             => $product->product_id,
                'model_code'             => 'MDL-' . uniqid(),
                'warranty_period_months' => $months,
                'is_serialized'          => true,
            ]);
        }

        return $product;
    }

    /**
     * Stock the product, open a pending issuance for one unit, and hand back
     * everything the caller needs to scan and complete it.
     *
     * @return array{user:User,issuance:Issuance,product:Product,customer:Customer,serial:?string}
     */
    private function makeSale(?int $months = 36, bool $withSerial = true): array
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct($months);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user     = $this->makeUser();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 5, 'available_quantity' => 5, 'reorder_level' => 0,
        ]);

        $receiving = Receiving::create([
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);

        (new InventoryBatchService())->createBatch(
            $product->product_id, $location->location_id, $receiving->receiving_id, 5, now()
        );

        $serial = null;
        if ($withSerial) {
            $serial = 'SN-' . strtoupper(uniqid());
            ItemSerial::create([
                'product_id'    => $product->product_id,
                'location_id'   => $location->location_id,
                'receiving_id'  => $receiving->receiving_id,
                'serial_number' => $serial,
                'serial_type'   => 'received',
                'status_id'     => self::SERIAL_IN_STOCK,
            ]);
        }

        $issuance = Issuance::create([
            'location_id'   => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number'     => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal'      => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by'     => $user->user_id, 'status_id' => self::ISSUANCE_PENDING,
        ]);

        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 1,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        return compact('user', 'issuance', 'product', 'customer', 'serial');
    }

    private function warrantiesFor(Product $product)
    {
        return WarrantyRecord::where('product_id', $product->product_id)->get();
    }

    /** THE path. A sold appliance leaves the shop with cover on record. */
    public function test_completing_a_sale_creates_the_warranty(): void
    {
        ['user' => $user, 'issuance' => $issuance, 'product' => $product,
         'customer' => $customer, 'serial' => $serial] = $this->makeSale(months: 36);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $serial,
        ])->assertJson(['success' => true]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/complete/{$issuance->issuance_id}")
            ->assertOk();

        $warranty = $this->warrantiesFor($product)->firstOrFail();

        $this->assertSame($serial, $warranty->serial_number);
        $this->assertSame((int) $customer->customer_id, (int) $warranty->customer_id);
        $this->assertSame(36, (int) $warranty->warranty_period_months);
        $this->assertSame(self::WARRANTY_ACTIVE, (int) $warranty->status_id);

        $saleDate = $issuance->issuance_date instanceof Carbon
            ? $issuance->issuance_date->toDateString()
            : (string) $issuance->issuance_date;

        $this->assertSame($saleDate, Carbon::parse($warranty->warranty_start_date)->toDateString());
        $this->assertSame(
            Carbon::parse($saleDate)->addMonths(36)->toDateString(),
            Carbon::parse($warranty->warranty_end_date)->toDateString(),
            'cover runs from the sale for the product’s own term'
        );
    }

    /** Consumables carry none, so a sale of one must not invent cover. */
    public function test_a_consumable_sale_creates_no_warranty(): void
    {
        ['user' => $user, 'issuance' => $issuance, 'product' => $product] =
            $this->makeSale(months: null, withSerial: false);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $product->product_code,
            'quantity'    => 1,
        ]);

        $this->actingAs($user)->postJson("/api/issuance-scan/complete/{$issuance->issuance_id}");

        $this->assertCount(0, $this->warrantiesFor($product));
    }

    /** Completion is keyed on the issuance detail, so a repeat cannot double-cover. */
    public function test_completing_twice_does_not_duplicate_the_warranty(): void
    {
        ['user' => $user, 'issuance' => $issuance, 'product' => $product, 'serial' => $serial] =
            $this->makeSale(months: 12);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $serial,
        ]);

        $this->actingAs($user)->postJson("/api/issuance-scan/complete/{$issuance->issuance_id}");
        $this->actingAs($user)->postJson("/api/issuance-scan/complete/{$issuance->issuance_id}");

        $this->assertCount(1, $this->warrantiesFor($product));
    }

    /** No term configured means no cover to record. */
    public function test_an_appliance_with_no_term_creates_no_warranty(): void
    {
        ['user' => $user, 'issuance' => $issuance, 'product' => $product, 'serial' => $serial] =
            $this->makeSale(months: 0);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $serial,
        ]);

        $this->actingAs($user)->postJson("/api/issuance-scan/complete/{$issuance->issuance_id}");

        $this->assertCount(0, $this->warrantiesFor($product));
    }

    /**
     * DOCUMENTS A KNOWN GAP -- this is not the behaviour anyone wants.
     *
     * An appliance sold without its serial scanned gets NO warranty and nobody
     * is told: the sale completes looking perfectly normal while the customer
     * walks out with cover that exists nowhere. Pinned here so the day someone
     * fixes it, this test fails and says so, rather than the gap staying
     * invisible. See the deferred section of
     * docs/superpowers/specs/2026-08-14-warranty-pipeline-design.md.
     */
    public function test_an_appliance_sold_without_a_serial_silently_gets_no_warranty(): void
    {
        ['user' => $user, 'issuance' => $issuance, 'product' => $product] =
            $this->makeSale(months: 36, withSerial: false);

        $issuance->details()->update(['quantity_issued' => 1]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/complete/{$issuance->issuance_id}")
            ->assertOk();

        $this->assertCount(0, $this->warrantiesFor($product),
            'no serial, no warranty -- and no warning either, which is the gap');
    }
}
