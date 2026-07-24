<?php

namespace Tests\Feature\Sets;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;
use Tests\TestCase;

abstract class SetTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function makeLocation(string $name = 'Main WH'): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    protected function makeUserAt(Location $location, string $role = 'warehouse_personnel'): User
    {
        $map = ['admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3, 'warehouse_personnel' => 4, 'auditor' => 5];
        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $map[$role] ?? 4,
            'location_id'   => $location->location_id,
        ]);
    }

    protected function makeConsumable(string $barcode = null, float $price = 50, float $cost = 30): Product
    {
        return Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Material',
            'product_type' => 'installation_material',
            'barcode'      => $barcode ?? ('BC-' . uniqid()),
            'unit_price'   => $price,
            'cost_price'   => $cost,
        ]);
    }

    protected function makeAppliance(string $barcode = null, float $price = 30000, float $cost = 24000): Product
    {
        return Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Aircon',
            'product_type' => 'appliance',
            'barcode'      => $barcode ?? ('BC-' . uniqid()),
            'unit_price'   => $price,
            'cost_price'   => $cost,
        ]);
    }

    protected function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    /** Batch with a real receiving. $attrs: quantity_remaining, received_at, zone_label. */
    protected function makeBatch(Product $product, Location $location, array $attrs = []): InventoryBatch
    {
        $rcv = $this->makeReceiving($location->location_id);
        return app(InventoryBatchService::class)->createBatch(
            $product->product_id,
            $location->location_id,
            $rcv->receiving_id,
            $attrs['quantity_remaining'] ?? 10,
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

    protected function makeCustomer(): Customer
    {
        return Customer::create([
            'customer_name' => 'Test Customer ' . uniqid(),
            'customer_type' => 'individual',
        ]);
    }

    protected function issuanceStatusId(string $name): int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', $name)
            ->value('status_id');
        if ($id === null) {
            throw new \RuntimeException("Unknown issuance status: {$name}");
        }
        return (int) $id;
    }
}
