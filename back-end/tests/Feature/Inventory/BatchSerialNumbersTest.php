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
use App\Models\PurchaseOrderDetail;

class BatchSerialNumbersTest extends TestCase
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

    /**
     * A receipt must resolve to exactly one PO line, so ensure one exists for
     * (po, location, product) with room for $qty. Topping up quantity_ordered
     * keeps repeat receipts against the same line receivable.
     */
    private function poLine(PurchaseOrder $po, int $loc, int $prod, int $qty): PurchaseOrderDetail
    {
        $line = PurchaseOrderDetail::firstOrCreate(
            ['po_id' => $po->po_id, 'location_id' => $loc, 'product_id' => $prod],
            ['quantity_ordered' => 0, 'unit_price' => '1.00', 'subtotal' => 0]
        );
        // Order at least what has already landed plus this receipt, so repeat
        // calls never double-count and a pre-ordered line is left alone.
        $received = (int) \Illuminate\Support\Facades\DB::table('receiving_details')
            ->where('po_detail_id', $line->po_detail_id)->sum('quantity_amount');
        $line->quantity_ordered = max((int) $line->quantity_ordered, $received + $qty);
        $line->save();

        return $line;
    }

    private function receive(User $user, PurchaseOrder $po, int $locationId, int $productId, int $qty, string $barcode): void
    {
        $line = $this->poLine($po, $locationId, $productId, $qty);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [[
                'po_detail_id'    => $line->po_detail_id,
                'product_id'      => $productId,
                'quantity_amount' => $qty,
                'condition'       => 'Good',
                'scanned_barcode' => $barcode,
            ]],
        ])->assertStatus(201);
    }

    public function test_appliance_batch_lists_its_serial_numbers_in_both_endpoints(): void
    {
        $loc = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance', // serialized by default
        ]);
        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $loc->location_id,
            'quantity_on_hand'   => 0,
            'available_quantity' => 0,
        ]);
        $user = $this->makeUser();
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $loc->location_id,
            'verification_status' => 'verified',
            'status_id'           => 8,
        ]);

        $serial = 'SN-' . uniqid();
        $this->receive($user, $po, $loc->location_id, $product->product_id, 1, $serial);

        // Per-inventory batches endpoint (Console expander).
        $batches = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/batches')
            ->assertStatus(200)
            ->json('data');
        $this->assertNotEmpty($batches);
        $this->assertContains($serial, $batches[0]['serials']);

        // Warehouse-map endpoint.
        $wm = $this->actingAs($user)
            ->getJson('/api/inventory-batches')
            ->assertStatus(200)
            ->json('data');
        $mine = collect($wm)->firstWhere('product_id', $product->product_id);
        $this->assertNotNull($mine);
        $this->assertContains($serial, $mine['serials']);
    }

    public function test_installation_material_batch_lists_scanned_barcode(): void
    {
        $loc = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Installation material',
            'product_type' => 'installation_material',
        ]);
        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $loc->location_id,
            'quantity_on_hand'   => 0,
            'available_quantity' => 0,
        ]);
        $user = $this->makeUser();
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $loc->location_id,
            'verification_status' => 'verified',
            'status_id'           => 8,
        ]);

        $barcode = 'BC-' . uniqid();
        $this->receive($user, $po, $loc->location_id, $product->product_id, 10, $barcode);

        $batches = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/batches')
            ->assertStatus(200)
            ->json('data');
        $this->assertNotEmpty($batches);
        $this->assertContains($barcode, $batches[0]['serials']);
    }
}
