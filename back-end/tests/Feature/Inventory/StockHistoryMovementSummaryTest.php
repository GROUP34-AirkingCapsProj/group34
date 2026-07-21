<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Transfer;
use App\Models\TransferDetail;

class StockHistoryMovementSummaryTest extends TestCase
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

    public function test_summary_splits_sold_and_transferred_and_reports_stock_in(): void
    {
        $source = Location::create([
            'location_code' => 'SRC-' . uniqid(),
            'location_name' => 'Source Loc',
            'location_type' => 'warehouse',
        ]);
        $dest = Location::create([
            'location_code' => 'DST-' . uniqid(),
            'location_name' => 'Dest Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);

        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $source->location_id,
            'quantity_on_hand'   => 30,
            'available_quantity' => 30,
        ]);

        // Stock In: two receiving events (12 + 8 = 20 pieces) at the source location.
        $user = $this->makeUser();
        $po = \App\Models\PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $source->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);
        foreach ([12, 8] as $qty) {
            $this->actingAs($user)->postJson('/api/receivings', [
                'pc_id'            => $po->po_id,
                'location_id'      => $source->location_id,
                'receiving_number' => 'RCV-' . uniqid(),
                'receiving_date'   => now()->toDateString(),
                'details'          => [
                    ['product_id' => $product->product_id, 'quantity_amount' => $qty, 'condition' => 'Good'],
                ],
            ])->assertStatus(201);
        }

        // Stock Out (sold): one issuance of 5 pieces at the source location.
        $issuance = Issuance::create([
            'location_id'   => $source->location_id,
            'issuance_date' => now()->toDateString(),
            'status_id'     => 1,
        ]);
        IssuanceDetail::create([
            'issuance_id'        => $issuance->issuance_id,
            'product_id'         => $product->product_id,
            'quantity_issued'    => 5,
            'quantity_in_pieces' => 5,
        ]);

        // Transfers: one dispatched transfer of 7 pieces OUT of the source location.
        $transfer = Transfer::create([
            'from_location_id'          => $source->location_id,
            'to_location_id'            => $dest->location_id,
            'transfer_number'           => 'TRF-' . uniqid(),
            'transfer_date'             => now(),
            'total_quantity_transferred'=> 7,
            'status_id'                 => 1,
        ]);
        TransferDetail::create([
            'transfer_id'          => $transfer->transfer_id,
            'product_id'           => $product->product_id,
            'quantity_requested'   => 7,
            'quantity_transferred' => 7,
        ]);

        $resp = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200);

        $summary = $resp->json('data.summary');

        $this->assertSame(20, $summary['stock_in'], 'stock_in should equal total received pieces');
        $this->assertSame(5, $summary['stock_out'], 'stock_out should count sold pieces only, not transfers');
        $this->assertSame(7, $summary['transferred'], 'transferred should count transferred pieces only, not sales');
    }
}
