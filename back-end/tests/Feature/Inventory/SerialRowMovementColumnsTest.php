<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Inventory;
use App\Models\PurchaseOrder;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\ItemSerial;

class SerialRowMovementColumnsTest extends TestCase
{
    use DatabaseTransactions;

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

    public function test_consumable_rows_carry_per_batch_in_and_out(): void
    {
        $loc = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);
        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $loc->location_id,
            'quantity_on_hand'   => 20,
            'available_quantity' => 20,
        ]);
        $user = $this->makeUser();
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $loc->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);

        // Two receiving batches, each with a distinct scanned barcode so both become rows.
        foreach ([['BC-A', 12], ['BC-B', 8]] as [$bc, $qty]) {
            $this->actingAs($user)->postJson('/api/receivings', [
                'pc_id'            => $po->po_id,
                'location_id'      => $loc->location_id,
                'receiving_number' => 'RCV-' . uniqid(),
                'receiving_date'   => now()->toDateString(),
                'details'          => [[
                    'product_id'      => $product->product_id,
                    'quantity_amount' => $qty,
                    'condition'       => 'Good',
                    'scanned_barcode' => $bc . '-' . uniqid(),
                ]],
            ])->assertStatus(201);
        }
        // Resolve the two receiving_ids for this product at this location, oldest first.
        $rows = DB::table('receiving_details')
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->where('receiving_details.product_id', $product->product_id)
            ->where('receivings.location_id', $loc->location_id)
            ->orderBy('receiving_details.created_at')
            ->get(['receiving_details.receiving_id']);
        $firstReceivingId = $rows[0]->receiving_id;

        // Sell 5 pieces attributed to the FIRST batch's receiving.
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'issuance_date' => now()->toDateString(),
            'status_id'     => 1,
        ]);
        IssuanceDetail::create([
            'issuance_id'        => $issuance->issuance_id,
            'product_id'         => $product->product_id,
            'receiving_id'       => $firstReceivingId,
            'quantity_issued'    => 5,
            'quantity_in_pieces' => 5,
        ]);

        $records = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');

        $byReceiving = [];
        foreach ($records as $r) {
            // Match rows back to their receiving via row_stock_in (12 vs 8).
            $byReceiving[$r['row_stock_in']] = $r;
        }

        $this->assertSame(12, $byReceiving[12]['row_stock_in']);
        $this->assertSame(5,  $byReceiving[12]['row_stock_out']);
        $this->assertNull($byReceiving[12]['row_transfer']);

        $this->assertSame(8, $byReceiving[8]['row_stock_in']);
        $this->assertSame(0, $byReceiving[8]['row_stock_out']);
        $this->assertNull($byReceiving[8]['row_transfer']);
    }

    public function test_appliance_rows_carry_unit_in_and_transfer_flag(): void
    {
        $loc = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
        ]);
        // Appliances are serialized by default (Product::isSerialized() returns true when
        // no appliance_details row exists), so stockHistory takes the item_serial branch.
        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $loc->location_id,
            'quantity_on_hand'   => 2,
            'available_quantity' => 2,
        ]);
        $user = $this->makeUser();

        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-IN-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-TR-' . uniqid(),
            'serial_type'   => 'transferred',
            'status_id'     => 26,
        ]);

        $records = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');

        $this->assertCount(2, $records);
        foreach ($records as $r) {
            $this->assertSame(1, $r['row_stock_in']);
            $this->assertNull($r['row_stock_out']);
        }
        $transferFlags = array_map(fn($r) => $r['row_transfer'], $records);
        sort($transferFlags);
        $this->assertSame([0, 1], $transferFlags);
    }
}
