<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Location;
use App\Models\Item;
use App\Models\Inventory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Role;
use App\Models\StatusLookup;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Create roles (positions) - role_name for middleware, description for display in UI
        $adminRole = Role::create(['role_name' => 'admin', 'description' => 'System Administrator']);
        Role::create(['role_name' => 'inventory_analyst', 'description' => 'Inventory Analyst']);
        Role::create(['role_name' => 'branch_manager', 'description' => 'Branch Manager']);
        Role::create(['role_name' => 'warehouse_personnel', 'description' => 'Warehouse Personnel']);
        Role::create(['role_name' => 'auditor', 'description' => 'Auditor']);

        // Create user statuses
        $activeStatus = StatusLookup::create(['status_name' => 'Active', 'status_category' => 'user']);
        StatusLookup::create(['status_name' => 'Inactive', 'status_category' => 'user']);

        // Create purchase order statuses
        StatusLookup::create(['status_name' => 'Pending', 'status_category' => 'purchase_order']);
        StatusLookup::create(['status_name' => 'Authorized', 'status_category' => 'purchase_order']);
        StatusLookup::create(['status_name' => 'Rejected', 'status_category' => 'purchase_order']);
        StatusLookup::create(['status_name' => 'Completed', 'status_category' => 'purchase_order']);

        // Create admin user
        User::create([
            'first_name' => 'System',
            'last_name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@airking.com',
            'password_hash' => Hash::make('123456'),
            'role_id' => $adminRole->role_id,
            'status_id' => $activeStatus->status_id,
        ]);

        // Create branches as locations with type 'branch_office'
        $branches = [
            ['location_code' => 'CDO', 'location_name' => 'Cagayan de Oro', 'capacity' => 6000],
            ['location_code' => 'DVO', 'location_name' => 'Davao City', 'capacity' => 10000],
            ['location_code' => 'VAL', 'location_name' => 'Valencia', 'capacity' => 2500],
            ['location_code' => 'BUT', 'location_name' => 'Butuan', 'capacity' => 4500],
            ['location_code' => 'ILI', 'location_name' => 'Iligan', 'capacity' => 3000],
            ['location_code' => 'PAG', 'location_name' => 'Pagadian', 'capacity' => 4000],
            ['location_code' => 'ZAM', 'location_name' => 'Zamboanga', 'capacity' => 5000],
        ];

        foreach ($branches as $branch) {
            Location::create([
                'location_code' => $branch['location_code'],
                'location_name' => $branch['location_name'],
                'location_type' => 'branch_office',
                'capacity'      => $branch['capacity'],
                'status_id'     => 1, // Active
            ]);
        }

        // Create items
        $items = [
            ['code' => 'AC-001', 'name' => 'Split Type 1HP', 'category' => 'Air Conditioning', 'price' => 15000],
            ['code' => 'AC-002', 'name' => 'Split Type 1.5HP', 'category' => 'Air Conditioning', 'price' => 20000],
            ['code' => 'AC-003', 'name' => 'Window Type 1HP', 'category' => 'Air Conditioning', 'price' => 12000],
            ['code' => 'TV-001', 'name' => '32" LED TV', 'category' => 'Television', 'price' => 8000],
            ['code' => 'TV-002', 'name' => '43" Smart TV', 'category' => 'Television', 'price' => 15000],
            ['code' => 'TV-003', 'name' => '55" 4K TV', 'category' => 'Television', 'price' => 25000],
            ['code' => 'TV-004', 'name' => '65" OLED TV', 'category' => 'Television', 'price' => 45000],
            ['code' => 'WM-001', 'name' => 'Top Load 7kg', 'category' => 'Washing Machine', 'price' => 10000],
            ['code' => 'WM-002', 'name' => 'Front Load 8kg', 'category' => 'Washing Machine', 'price' => 18000],
            ['code' => 'WM-003', 'name' => 'Twin Tub 10kg', 'category' => 'Washing Machine', 'price' => 12000],
        ];

        foreach ($items as $item) {
            Item::create($item);
        }

        // Create inventory
        $branchIds = Location::where('location_type', 'branch_office')->pluck('location_id')->toArray();
        $itemIds = Item::pluck('id')->toArray();

        foreach ($branchIds as $branchId) {
            foreach ($itemIds as $itemId) {
                Inventory::create([
                    'branch_id' => $branchId,
                    'item_id' => $itemId,
                    'quantity' => rand(3, 50),
                    'reserved' => rand(0, 5),
                ]);
            }
        }

        // Create transactions
        $user = User::first();

        for ($i = 0; $i < 100; $i++) {
            Transaction::create([
                'transaction_code' => 'TXN-' . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'branch_id' => $branchIds[array_rand($branchIds)],
                'item_id' => $itemIds[array_rand($itemIds)],
                'user_id' => $user->user_id,
                'type' => ['purchase', 'sales'][array_rand(['purchase', 'sales'])],
                'quantity' => rand(1, 10),
                'amount' => rand(5000, 50000),
                'created_at' => now()->subDays(rand(0, 30)),
            ]);
        }

        $this->call(CategoriesAndBrandsSeeder::class);
    }
}

