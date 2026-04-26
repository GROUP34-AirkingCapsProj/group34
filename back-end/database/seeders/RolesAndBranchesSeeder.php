<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Location;

/**
 * Adds roles (positions) and branches to the database.
 * Run on existing DB: php artisan db:seed --class=RolesAndBranchesSeeder
 */
class RolesAndBranchesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['role_name' => 'admin', 'description' => 'System Administrator'],
            ['role_name' => 'inventory_analyst', 'description' => 'Inventory Analyst'],
            ['role_name' => 'branch_manager', 'description' => 'Branch Manager'],
            ['role_name' => 'warehouse_personnel', 'description' => 'Warehouse Personnel'],
            ['role_name' => 'auditor', 'description' => 'Auditor'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['role_name' => $role['role_name']],
                ['description' => $role['description']]
            );
        }
        $this->command->info('Roles synced.');

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
            Location::firstOrCreate(
                ['location_code' => $branch['location_code'], 'location_type' => 'branch_office'],
                [
                    'location_name' => $branch['location_name'],
                    'capacity'      => $branch['capacity'],
                    'status_id'     => 1, // Active
                ]
            );
        }
        $this->command->info('Branches synced.');
    }
}

