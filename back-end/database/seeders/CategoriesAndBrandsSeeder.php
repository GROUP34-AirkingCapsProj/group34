<?php

namespace Database\Seeders;

use App\Models\CategoryLookup;
use App\Models\BrandLookup;
use Illuminate\Database\Seeder;

class CategoriesAndBrandsSeeder extends Seeder
{
    public function run(): void
    {
        // category_type must be 'appliance' or 'installation_material' —
        // anything else (the old 'product' default) is invisible in both
        // Item Master dropdowns. All five of these are appliances by name.
        $categories = [
            ['category_name' => 'Air Conditioning', 'category_type' => 'appliance', 'description' => 'Air conditioning units'],
            ['category_name' => 'Television', 'category_type' => 'appliance', 'description' => 'TVs and displays'],
            ['category_name' => 'Washing Machine', 'category_type' => 'appliance', 'description' => 'Washing machines and dryers'],
            ['category_name' => 'Refrigerator', 'category_type' => 'appliance', 'description' => 'Refrigerators and freezers'],
            ['category_name' => 'Small Appliances', 'category_type' => 'appliance', 'description' => 'Small home appliances'],
        ];

        foreach ($categories as $c) {
            CategoryLookup::firstOrCreate(
                ['category_name' => $c['category_name']],
                $c
            );
        }

        $brands = [
            ['brand_name' => 'Airking', 'description' => null],
            ['brand_name' => 'Samsung', 'description' => null],
            ['brand_name' => 'LG', 'description' => null],
            ['brand_name' => 'Panasonic', 'description' => null],
            ['brand_name' => 'Sony', 'description' => null],
            ['brand_name' => 'Whirlpool', 'description' => null],
            ['brand_name' => 'Condura', 'description' => null],
            ['brand_name' => 'Hanabishi', 'description' => null],
            ['brand_name' => 'Imarflex', 'description' => null],
            ['brand_name' => 'TCL', 'description' => null],
        ];

        foreach ($brands as $b) {
            BrandLookup::firstOrCreate(
                ['brand_name' => $b['brand_name']],
                $b
            );
        }
    }
}
