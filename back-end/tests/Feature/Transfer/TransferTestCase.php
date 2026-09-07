<?php

namespace Tests\Feature\Transfer;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Receiving;
use App\Models\InventoryBatch;
use App\Services\InventoryBatchService;

abstract class TransferTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function statusId(string $name): int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $name)
            ->value('status_id');
        if ($id === null) {
            throw new \RuntimeException("Unknown transfer status: {$name}");
        }
        return (int) $id;
    }

    protected function makeLocation(string $name): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    protected function makeUser(): User
    {
        $roleId = (int) (\Illuminate\Support\Facades\DB::table('roles')->min('role_id') ?? 1);
        return User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    protected function makeUserAt(\App\Models\Location $location, string $role): \App\Models\User
    {
        $map = ['admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3, 'warehouse_personnel' => 4, 'auditor' => 5];
        return \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $map[$role] ?? 4,
            'location_id'   => $location->location_id,
        ]);
    }

    protected function makeInstallationMaterial(): Product
    {
        return Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Installation material',
            'product_type' => 'installation_material',
        ]);
    }

    protected function makeAppliance(): Product
    {
        return Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
        ]);
    }

    protected function setStock(int $locationId, int $productId, int $qty): Inventory
    {
        return Inventory::create([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $qty,
            'available_quantity' => $qty,
            'reorder_level'      => 0,
        ]);
    }

    /** Create $count 'received' serials for an appliance at a location. Returns serial_number strings. */
    protected function makeSerials(int $locationId, int $productId, int $count): array
    {
        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $sn = 'SN-' . uniqid();
            ItemSerial::create([
                'product_id'    => $productId,
                'location_id'   => $locationId,
                'serial_number' => $sn,
                'serial_type'   => 'received',
                'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
            ]);
            $numbers[] = $sn;
        }
        return $numbers;
    }

    /** Create an IST (transfer_request_id set) at status Approved with given lines. */
    protected function makeApprovedIst(int $from, int $to, array $lines): Transfer
    {
        $req = Transfer::create([
            'transfer_request_id' => null,
            'from_location_id'    => $from,
            'to_location_id'      => $to,
            'transfer_number'     => 'TR-REQ-TEST-' . uniqid(),
            'transfer_date'       => now(),
            'status_id'           => $this->statusId('Pending'),
        ]);

        $ist = Transfer::create([
            'transfer_request_id' => $req->transfer_id,
            'from_location_id'    => $from,
            'to_location_id'      => $to,
            'transfer_number'     => 'IST-TEST-' . uniqid(),
            'transfer_date'       => now(),
            'status_id'           => $this->statusId('Approved'),
        ]);

        foreach ($lines as $line) {
            $ist->details()->create([
                'product_id'           => $line['product_id'],
                'quantity_requested'   => $line['quantity_requested'],
                'quantity_scanned'     => $line['quantity_scanned'] ?? 0,
                'quantity_transferred' => 0,
                'quantity_received'    => 0,
            ]);
        }

        return $ist->fresh('details');
    }

    protected function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    /** Create a batch (with a real receiving) at a location. $attrs: zone_label, quantity_remaining, received_at. */
    protected function makeBatch(Product $product, Location $location, array $attrs = []): InventoryBatch
    {
        $rcv = $this->makeReceiving($location->location_id);
        return app(InventoryBatchService::class)->createBatch(
            $product->product_id,
            $location->location_id,
            $rcv->receiving_id,
            $attrs['quantity_remaining'] ?? 1,
            $attrs['received_at'] ?? now(),
            $attrs['zone_label'] ?? null
        );
    }

    protected function makeSerial(Product $product, Location $location, string $sn, ?int $receivingId = null): ItemSerial
    {
        return ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $receivingId,
            'serial_number' => $sn,
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);
    }

    /** New single-row transfer (transfer_request_id null, intent demand) at Approved with $lines = [[Product,$qty],...]. */
    protected function makeApprovedTransfer(Location $from, Location $to, array $lines): Transfer
    {
        $t = Transfer::create([
            'transfer_request_id' => null,
            'from_location_id'    => $from->location_id,
            'to_location_id'      => $to->location_id,
            'intent'              => 'demand',
            'transfer_number'     => 'TST-' . uniqid(),
            'transfer_date'       => now(),
            'status_id'           => $this->statusId('Approved'),
        ]);
        foreach ($lines as [$product, $qty]) {
            $t->details()->create([
                'product_id'           => $product->product_id,
                'quantity_requested'   => $qty,
                'quantity_scanned'     => 0,
                'quantity_transferred' => 0,
                'quantity_received'    => 0,
            ]);
        }
        return $t->fresh('details.product');
    }
}
