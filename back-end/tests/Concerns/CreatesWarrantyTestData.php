<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\User;
use App\Models\WarrantyRecord;
use Illuminate\Support\Carbon;

trait CreatesWarrantyTestData
{
    protected function actingUser(): User
    {
        $user = User::query()->first();
        $this->assertNotNull($user, 'Test DB must contain at least one user.');
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_code'  => 'TST-' . uniqid(),
            'customer_type'  => 'registered',
            'customer_name'  => 'Test Customer',
            'status_id'      => 36,
            'outstanding_balance' => 0,
        ], $overrides));
    }

    /** An appliance product with a serialized unit in stock + an active warranty record. */
    protected function makeAppliance(Customer $customer, array $warrantyOverrides = []): array
    {
        $product = Product::where('product_type', 'appliance')->first();
        $this->assertNotNull($product, 'Test DB must contain at least one appliance product.');

        $location = \App\Models\Location::where('is_active', 1)->first();
        $this->assertNotNull($location, 'Test DB must contain an active location.');

        $serial = 'SN-' . strtoupper(uniqid());
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'serial_number' => $serial,
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);

        Inventory::updateOrCreate(
            ['product_id' => $product->product_id, 'location_id' => $location->location_id],
            ['quantity_on_hand' => 5, 'available_quantity' => 5, 'packages_on_hand' => 0],
        );

        $warranty = WarrantyRecord::create(array_merge([
            'product_id'             => $product->product_id,
            'serial_number'          => $serial,
            'customer_id'            => $customer->customer_id,
            'sale_date'              => Carbon::today()->subMonths(2)->toDateString(),
            'warranty_start_date'    => Carbon::today()->subMonths(2)->toDateString(),
            'warranty_end_date'      => Carbon::today()->addMonths(10)->toDateString(),
            'warranty_period_months' => 12,
            'warranty_duration_label'=> '1 Year',
            'status_id'              => 33,
        ], $warrantyOverrides));

        return [
            'product'  => $product,
            'location' => $location,
            'serial'   => $serial,
            'warranty' => $warranty,
        ];
    }

    /** A consumable product (spare part) with stock at the given location. */
    protected function makeConsumable(int $locationId, int $qty = 100): Product
    {
        $product = Product::where('product_type', 'consumable')->first();
        $this->assertNotNull($product, 'Test DB must contain at least one consumable product.');

        Inventory::updateOrCreate(
            ['product_id' => $product->product_id, 'location_id' => $locationId],
            ['quantity_on_hand' => $qty, 'available_quantity' => $qty, 'packages_on_hand' => 0],
        );

        return $product;
    }
}
