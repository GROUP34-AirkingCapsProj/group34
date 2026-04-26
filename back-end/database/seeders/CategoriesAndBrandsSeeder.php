<?php

namespace Database\Seeders;

use App\Models\CategoryLookup;
use App\Models\BrandLookup;
use Illuminate\Database\Seeder;

class CategoriesAndBrandsSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['category_name' => 'Air Conditioning', 'category_type' => 'product', 'description' => 'Air conditioning units'],
            ['category_name' => 'Television', 'category_type' => 'product', 'description' => 'TVs and displays'],
            ['category_name' => 'Washing Machine', 'category_type' => 'product', 'description' => 'Washing machines and dryers'],
            ['category_name' => 'Refrigerator', 'category_type' => 'product', 'description' => 'Refrigerators and freezers'],
            ['category_name' => 'Small Appliances', 'category_type' => 'product', 'description' => 'Small home appliances'],
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
