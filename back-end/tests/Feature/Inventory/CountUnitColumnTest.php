<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CountUnitColumnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_count_unit_and_entry_mode_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('installation_material_details', 'count_unit'));
        $this->assertTrue(Schema::hasColumn('receiving_details', 'entry_mode'));
    }

    public function test_count_unit_defaults_to_package_and_is_mass_assignable(): void
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);

        $detail = InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100, 'is_serialized' => 0,
        ]);
        $this->assertSame('package', $detail->fresh()->count_unit);

        $detail->update(['count_unit' => 'piece']);
        $this->assertSame('piece', $detail->fresh()->count_unit);
    }
}
