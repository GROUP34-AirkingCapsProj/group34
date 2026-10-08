<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryScanEvent;
use App\Models\ItemSerial;
use App\Models\Issuance;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

/**
 * inventory_scan_events.action is the column both readers filter on:
 * InventoryScanEventController::index (the /api/scan-events feed) and
 * InventoryScanTransactionController's barcode-history panel both ask for
 * action = 'applied'.
 *
 * IssuanceScanController wrote 'accepted' instead, so every event it logged
 * was written to a table nothing could read it back from — invisible in the
 * feed and invisible in the history panel, while the scan response itself
 * cheerfully reported "action": "applied".
 *
 * These tests pin the writer and the reader to the same vocabulary.
 */
class ScanEventActionConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Stands up the smallest fixture that lets a serialized appliance be
     * scanned onto a draft issuance. Returns the actor, the product and the
     * serial number to scan.
     */
    private function makeScannableIssuance(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);

        $product = Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
            'unit_price'   => 100,
        ]);

        $user = User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => (int) (DB::table('roles')->min('role_id') ?? 1),
        ]);

        $customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_type' => 'individual',
        ]);

        Inventory::create([
            'location_id'       => $location->location_id,
            'product_id'        => $product->product_id,
            'quantity_on_hand'  => 1,
            'available_quantity' => 1,
            'reorder_level'     => 0,
        ]);

        $rcv = Receiving::create([
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);

        (new InventoryBatchService())->createBatch(
            $product->product_id, $location->location_id, $rcv->receiving_id, 1, now()
        );

        $serialNumber = 'SN-' . uniqid();
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $serialNumber,
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);

        $issuance = Issuance::create([
            'location_id'      => $location->location_id,
            'customer_id'      => $customer->customer_id,
            'issuance_date'    => now()->toDateString(),
            'issuance_type'    => 'individual',
            'dr_number'        => 'DR-TEST-' . uniqid(),
            'discount_type'    => 'none',
            'discount_percent' => 0,
            'subtotal'         => 0,
            'discount_amount'  => 0,
            'total_amount'     => 0,
            'issued_by'        => $user->user_id,
            'status_id'        => 30,
        ]);

        $issuance->details()->create([
            'product_id'         => $product->product_id,
            'quantity_requested' => 1,
            'quantity_issued'    => 0,
            'unit_price'         => 0,
            'subtotal'           => 0,
        ]);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $serialNumber,
        ])->assertJson(['success' => true]);

        return [$user, $product, $serialNumber];
    }

    public function test_issuance_scan_records_the_action_the_readers_filter_on(): void
    {
        [, $product] = $this->makeScannableIssuance();

        $event = InventoryScanEvent::where('product_id', $product->product_id)
            ->where('transaction_type', 'issuance')
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'The issuance scan logged no scan event at all.');
        $this->assertSame(
            'applied',
            $event->action,
            'The issuance writer must use the same action value the scan-events readers filter on.'
        );
    }

    public function test_issuance_scan_event_appears_in_the_scan_events_feed(): void
    {
        [$user, $product] = $this->makeScannableIssuance();

        $data = $this->actingAs($user)->getJson('/api/scan-events?per_page=200')
            ->assertStatus(200)
            ->json('data');

        $productIds = collect($data)->pluck('product_id')->all();
        $this->assertContains(
            $product->product_id,
            $productIds,
            'An issuance scan event never reaches the /api/scan-events feed.'
        );
    }
}
