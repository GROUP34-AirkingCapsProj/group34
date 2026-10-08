<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The PO receiving screen's count-entry panel prompts ("How many Boxes?" vs
 * "How many pieces?") and its live piece conversion are driven entirely by
 * count_unit on the PO line. If the payload omits it the panel silently
 * assumes 'package' and shows the clerk a piece total the server will not
 * apply, so the field has to be on the line — flat and nested.
 */
class PoLineCountUnitPayloadTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function makePoWithCountUnit(?string $countUnit): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create(array_merge([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0,
        ], $countUnit !== null ? ['count_unit' => $countUnit] : []));

        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        return [$po, $this->makeUser()];
    }

    public function test_po_line_emits_piece_count_unit_flat_and_nested(): void
    {
        [$po, $user] = $this->makePoWithCountUnit('piece');

        $line = $this->actingAs($user, 'sanctum')
            ->getJson("/api/purchase-orders/{$po->po_id}")
            ->assertOk()
            ->json('data.details.0');

        $this->assertSame('piece', $line['count_unit'], 'flat count_unit on the PO line');
        $this->assertSame('piece', $line['product']['count_unit'], 'nested product.count_unit');
    }

    public function test_po_line_count_unit_defaults_to_package(): void
    {
        [$po, $user] = $this->makePoWithCountUnit(null);

        $line = $this->actingAs($user, 'sanctum')
            ->getJson("/api/purchase-orders/{$po->po_id}")
            ->assertOk()
            ->json('data.details.0');

        $this->assertSame('package', $line['count_unit']);
        $this->assertSame('package', $line['product']['count_unit']);
    }
}
