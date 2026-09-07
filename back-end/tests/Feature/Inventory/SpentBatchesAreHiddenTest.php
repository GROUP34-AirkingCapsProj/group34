<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

/**
 * A batch drained to zero is history, not stock on a shelf.
 *
 * The three batch views disagreed about this. The Warehouse Map filtered on
 * quantity_remaining > 0; the PO Console and the inventory drill-down did not.
 * So after a transfer emptied a batch at its source warehouse, two of the three
 * screens went on listing it there - which reads to a warehouse hand as "the
 * stock I moved out is somehow still here", and is exactly the kind of ghost
 * that makes people stop trusting the numbers.
 *
 * The movement history in inventory_batch_movements is where a spent batch's
 * story lives. These views answer "what is here now".
 */
class SpentBatchesAreHiddenTest extends TestCase
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

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Warehouse A',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Installation material',
            'product_type' => 'installation_material',
        ]);
    }

    public function test_the_inventory_drill_down_omits_a_batch_with_nothing_left_in_it(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct();
        $user     = $this->makeUser();

        $inv = Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_on_hand'   => 5,
            'available_quantity' => 5,
            'reorder_level'      => 0,
        ]);

        $svc   = new InventoryBatchService();
        $spent = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()->subDays(9)]);
        $live  = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);

        $drained = $svc->createBatch($product->product_id, $location->location_id, $spent->receiving_id, 4, now()->subDays(9));
        $drained->update(['quantity_remaining' => 0]);
        $svc->createBatch($product->product_id, $location->location_id, $live->receiving_id, 5, now());

        $data = $this->actingAs($user)
            ->getJson("/api/inventory/{$inv->inventory_id}/batches")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data, 'only the batch that still holds stock belongs on this screen');
        $this->assertSame($live->receiving_id, $data[0]['receiving_id']);
    }

    public function test_the_po_console_omits_a_batch_with_nothing_left_in_it(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct();
        $user     = $this->makeUser();

        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $location->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
            'order_date'          => now()->toDateString(),
        ]);

        $svc = new InventoryBatchService();

        $spentRcv = Receiving::create([
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->subDays(9),
        ]);
        $liveRcv = Receiving::create([
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);

        $drained = $svc->createBatch($product->product_id, $location->location_id, $spentRcv->receiving_id, 4, now()->subDays(9));
        $drained->update(['quantity_remaining' => 0]);
        $liveBatch = $svc->createBatch($product->product_id, $location->location_id, $liveRcv->receiving_id, 5, now());

        $groups = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups')
            ->assertOk()
            ->json('data');

        $mine = collect($groups)->firstWhere('po_id', $po->po_id);
        $this->assertNotNull($mine);
        $this->assertCount(1, $mine['batches'], 'a warehouse that has shipped a batch out should stop being credited with it');
        $this->assertSame($liveBatch->batch_id, $mine['batches'][0]['batch_id']);
        $this->assertSame(5, $mine['total_remaining']);
    }

    public function test_a_po_whose_stock_has_all_left_the_warehouse_drops_off_the_console(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct();
        $user     = $this->makeUser();

        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $location->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
            'order_date'          => now()->toDateString(),
        ]);

        $rcv = Receiving::create([
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);

        $batch = (new InventoryBatchService())
            ->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 3, now());
        $batch->update(['quantity_remaining' => 0]);

        $groups = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups')
            ->assertOk()
            ->json('data');

        $this->assertNull(
            collect($groups)->firstWhere('po_id', $po->po_id),
            'an empty group is a warehouse claiming stock it no longer holds'
        );

        // The row itself is untouched - hidden from a "what is here now" view,
        // still on the books for anyone auditing where the stock went.
        $this->assertNotNull(InventoryBatch::find($batch->batch_id));
    }
}
