<?php

namespace Tests\Feature\Procurement;

use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Services\IncomingStockService;
use App\Services\ReceivingDraftSubmitService;
use Illuminate\Support\Facades\DB;

class PoReceivingProtectionTest extends ProcurementTestCase
{
    private function setupOrder(): array
    {
        $po = $this->makePo($this->makePr(100), 100, 8);
        $po->update(['verification_status' => 'verified']);
        $product = Product::create([
            'product_code' => 'SAFE-'.uniqid(), 'product_name' => 'Material',
            'product_type' => 'installation_material', 'recommended_stocks' => 50,
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 10, 'count_unit' => 'package',
        ]);
        $line = $po->details()->create([
            'location_id' => $this->locationId, 'product_id' => $product->product_id,
            'quantity_ordered' => 2, 'unit_price' => '50.00', 'subtotal' => '100.00',
        ]);

        return [$po, $line, $product];
    }

    private function receiveCount(PurchaseOrderDetail $line, int $pieces, bool $accept = false)
    {
        return $this->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $line->po_detail_id, 'location_id' => $this->locationId,
            'quantity' => 1, 'actual_pieces' => $pieces, 'accept_overage' => $accept,
        ]);
    }

    public function test_analyst_can_receive_without_transfer_permission_and_snapshot_is_used(): void
    {
        [$po, $line, $product] = $this->setupOrder();
        InstallationMaterialDetail::where('product_id', $product->product_id)->update(['pieces_per_package' => 100]);
        $this->actingAs($this->makeUser('inventory_analyst'));
        $this->receiveCount($line, 7)->assertOk()->assertJsonPath('data.action', 'applied')->assertJsonPath('data.pieces_expected', 10);
        $this->assertSame('partially_received', $po->fresh()->lifecycle_status);
        $this->assertSame(13, app(IncomingStockService::class)->byWarehouse()[$this->locationId][$product->product_id]);
        $this->postJson('/api/inventory/scan-transaction', ['transaction_type' => 'transfer', 'barcode' => 'anything'])->assertForbidden();
    }

    public function test_zero_tolerance_cannot_be_bypassed_and_receipts_remain_unchanged(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->receiveCount($line, 7)->assertJsonPath('data.action', 'applied');
        $before = DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->get()->toJson();
        $this->receiveCount($line, 14, true)->assertJsonPath('data.action', 'over_receipt');
        $this->assertSame($before, DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->get()->toJson());
        $this->assertSame(7, Inventory::where('product_id', $line->product_id)->value('quantity_on_hand'));
    }

    public function test_remaining_is_netted_per_line_and_excludes_drafts_and_closed_quantities(): void
    {
        [$po, $line, $product] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->receiveCount($line, 7)->assertJsonPath('data.action', 'applied');
        $line->update(['short_closed_quantity' => 5]);
        $other = $po->details()->create([
            'product_id' => $product->product_id, 'quantity_ordered' => 1,
            'unit_price' => '50.00', 'subtotal' => '50.00',
        ]); // Legacy header warehouse fallback.
        $draft = $this->makePo($this->makePr(100), 100, 7);
        $draft->details()->create(['product_id' => $product->product_id, 'quantity_ordered' => 99, 'unit_price' => '50.00']);
        $this->assertSame(18, app(IncomingStockService::class)->byWarehouse()[$this->locationId][$product->product_id]);
        $other->update(['short_closed_quantity' => 10]);
        $this->assertSame(8, app(IncomingStockService::class)->byWarehouse()[$this->locationId][$product->product_id]);
    }

    public function test_closed_po_blocks_count_and_staged_submission(): void
    {
        foreach (['short_closed', 'cancelled', 'fully_received'] as $state) {
            [$po, $line] = $this->setupOrder();
            $po->update(['lifecycle_status' => $state]);
            if ($state === 'fully_received') {
                DB::table('purchase_orders')->where('po_id', $po->po_id)->update(['status_id' => 11]);
            }
            $user = $this->makeUser('admin');
            $this->actingAs($user);
            $this->receiveCount($line, 1)->assertJsonPath('data.action', 'rejected');
            $draft = ReceivingDraft::create(['po_id' => $po->po_id, 'location_id' => $this->locationId, 'opened_by' => $user->user_id, 'status' => 'open']);
            ReceivingDraftItem::create([
                'draft_id' => $draft->draft_id, 'po_detail_id' => $line->po_detail_id,
                'product_id' => $line->product_id, 'entry_mode' => 'counted', 'quantity_amount' => 1,
                'units_entered' => 1, 'contents_verified' => true,
            ]);
            $result = app(ReceivingDraftSubmitService::class)->submit($draft, $user->user_id, true);
            $this->assertSame('failed', $result['action']);
            $this->assertSame(0, $po->receivings()->count());
        }
    }

    public function test_legacy_receiving_cannot_overfill_or_use_another_po_line(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $payload = ['pc_id' => $po->po_id, 'location_id' => $this->locationId, 'receiving_date' => today()->toDateString(),
            'details' => [['po_detail_id' => $line->po_detail_id, 'product_id' => $line->product_id, 'quantity_amount' => 21]]];
        $this->postJson('/api/receivings', $payload)->assertStatus(422);
        $this->assertSame(0, $po->receivings()->count());
        $payload['details'][0]['quantity_amount'] = 7;
        $this->postJson('/api/receivings', $payload)->assertCreated();
        $this->assertSame(7, Inventory::where('product_id', $line->product_id)->value('quantity_on_hand'));
        $this->assertSame(7, (int) $po->receivings()->first()->total_quantity_received);
    }

    public function test_approved_lines_and_derived_status_cannot_be_replaced(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->putJson('/api/purchase-orders/'.$po->po_id, ['details' => [
            ['product_id' => $line->product_id, 'quantity_ordered' => 1, 'unit_price' => '1.00'],
        ]])->assertStatus(422);
        $this->putJson('/api/purchase-orders/'.$po->po_id, ['status_id' => 11])->assertStatus(422);
        $this->assertDatabaseHas('purchase_order_details', ['po_detail_id' => $line->po_detail_id, 'quantity_ordered' => 2]);
    }

    public function test_receipt_and_referenced_po_cannot_be_deleted(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->receiveCount($line, 7)->assertJsonPath('data.action', 'applied');
        $receipt = $po->receivings()->first();
        $this->deleteJson('/api/receivings/'.$receipt->receiving_id)->assertStatus(409);
        DB::table('purchase_orders')->where('po_id', $po->po_id)->update(['deleted_at' => now()]);
        $this->deleteJson('/api/purchase-orders/'.$po->po_id.'/force')->assertStatus(409);
        $this->assertDatabaseHas('receivings', ['receiving_id' => $receipt->receiving_id]);
    }

    public function test_fully_received_verification_cannot_add_stock(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->postJson('/api/inventory/receive-count', ['po_detail_id' => $line->po_detail_id, 'quantity' => 2, 'actual_pieces' => 20])
            ->assertJsonPath('data.action', 'applied');
        $this->postJson('/api/inventory/receive-verify', ['po_detail_id' => $line->po_detail_id, 'packages' => 2, 'actual_pieces' => 20])
            ->assertJsonPath('data.action', 'applied');
        $this->postJson('/api/inventory/receive-verify', ['po_detail_id' => $line->po_detail_id, 'packages' => 2, 'actual_pieces' => 21, 'accept_overage' => true])
            ->assertJsonPath('data.action', 'over_receipt');
        $this->assertSame(20, Inventory::where('product_id', $line->product_id)->value('quantity_on_hand'));
    }

    public function test_overfilled_historical_line_does_not_hide_another_lines_incoming_quantity(): void
    {
        [$po, $line, $product] = $this->setupOrder();
        $receipt = Receiving::create(['po_id' => $po->po_id, 'location_id' => $this->locationId,
            'receiving_number' => 'OLD-'.uniqid(), 'receiving_date' => today()]);
        DB::table('receiving_details')->insert(['receiving_id' => $receipt->receiving_id, 'po_detail_id' => $line->po_detail_id,
            'product_id' => $product->product_id, 'quantity_amount' => 40, 'prod_price' => '5.00']);
        $po->details()->create(['product_id' => $product->product_id, 'location_id' => $this->locationId,
            'quantity_ordered' => 1, 'unit_price' => '50.00']);
        $this->assertSame(10, app(IncomingStockService::class)->byWarehouse()[$this->locationId][$product->product_id]);
    }

    public function test_analyst_cannot_approve_but_branch_manager_can(): void
    {
        [$po] = $this->setupOrder();
        DB::table('purchase_orders')->where('po_id', $po->po_id)->update(['status_id' => 7, 'lifecycle_status' => 'draft']);
        $this->actingAs($this->makeUser('inventory_analyst'))->putJson('/api/purchase-orders/'.$po->po_id, ['status_id' => 10])->assertStatus(422);
        $manager = $this->makeUser('branch_manager');
        $this->actingAs($manager)->putJson('/api/purchase-orders/'.$po->po_id, ['status_id' => 10])->assertOk();
        $this->assertSame($manager->user_id, (int) $po->fresh()->approved_by);
    }

    public function test_a_second_receiving_attempt_uses_the_current_balance(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->receiveCount($line, 15)->assertJsonPath('data.action', 'applied');
        // Reuse the stale line object and the old client quantity; the locked server state wins.
        $this->receiveCount($line, 6)->assertJsonPath('data.action', 'over_receipt');
        $this->assertSame(15, (int) Inventory::where('product_id', $line->product_id)->value('quantity_on_hand'));
    }

    public function test_ambiguous_historical_receipts_block_new_stock(): void
    {
        [$po, $line] = $this->setupOrder();
        $receipt = Receiving::create(['po_id' => $po->po_id, 'location_id' => $this->locationId,
            'receiving_number' => 'OLD-'.uniqid(), 'receiving_date' => today()]);
        DB::table('receiving_details')->insert(['receiving_id' => $receipt->receiving_id,
            'product_id' => $line->product_id, 'quantity_amount' => 5, 'prod_price' => '5.00']);
        $this->actingAs($this->makeUser('admin'));
        $this->receiveCount($line, 5)->assertJsonPath('data.action', 'rejected');
        $this->assertFalse(Inventory::where('product_id', $line->product_id)->exists());
    }

    public function test_verification_reconciles_all_receipt_batches_and_preserves_original_ledger_entries(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        foreach ([7, 5] as $qty) {
            $this->postJson('/api/receivings', ['pc_id' => $po->po_id, 'location_id' => $this->locationId,
                'receiving_date' => today()->toDateString(), 'details' => [
                    ['po_detail_id' => $line->po_detail_id, 'product_id' => $line->product_id, 'quantity_amount' => $qty],
                ]])->assertCreated();
        }
        $original = DB::table('inventory_ledger')->where('product_id', $line->product_id)->orderBy('ledger_id')->get()->toJson();
        $this->postJson('/api/inventory/receive-verify', ['po_detail_id' => $line->po_detail_id, 'packages' => 2, 'actual_pieces' => 4])
            ->assertJsonPath('data.action', 'applied');
        $this->assertSame(4, (int) Inventory::where('product_id', $line->product_id)->value('quantity_on_hand'));
        $this->assertSame(4, (int) DB::table('inventory_batches')->where('product_id', $line->product_id)->sum('quantity_remaining'));
        $this->assertSame(4, (int) DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->sum('quantity_amount'));
        $this->assertSame($original, DB::table('inventory_ledger')->where('product_id', $line->product_id)
            ->where('movement_type', 'receiving')->orderBy('ledger_id')->get()->toJson());
        $this->assertSame(8, (int) DB::table('inventory_ledger')->where('product_id', $line->product_id)
            ->where('movement_type', 'receiving_verification')->sum('quantity'));
    }

    public function test_verification_cannot_remove_consumed_stock_from_a_receipt_batch(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->receiveCount($line, 10)->assertJsonPath('data.action', 'applied');
        DB::table('inventory_batches')->where('product_id', $line->product_id)->update(['quantity_remaining' => 2]);
        $before = DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->get()->toJson();
        $this->postJson('/api/inventory/receive-verify', ['po_detail_id' => $line->po_detail_id, 'packages' => 1, 'actual_pieces' => 5])
            ->assertJsonPath('data.action', 'rejected');
        $this->assertSame($before, DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->get()->toJson());
        $this->assertSame(10, (int) Inventory::where('product_id', $line->product_id)->value('quantity_on_hand'));
    }

    public function test_warehouse_receiving_cannot_target_another_warehouse(): void
    {
        [$po, $line] = $this->setupOrder();
        $other = Location::create(['location_code' => 'OTHER-'.uniqid(), 'location_name' => 'Other', 'location_type' => 'warehouse']);
        $user = $this->makeUser('warehouse_personnel');
        $user->update(['location_id' => $other->location_id]);
        $this->actingAs($user);
        $this->receiveCount($line, 5)->assertJsonPath('data.action', 'rejected');
        $this->assertFalse(Inventory::where('product_id', $line->product_id)->exists());
    }

    public function test_recommendation_snapshot_refreshes_when_incoming_quantity_changes(): void
    {
        [$po, $line, $product] = $this->setupOrder();
        Inventory::create(['location_id' => $this->locationId, 'product_id' => $product->product_id,
            'quantity_on_hand' => 0, 'available_quantity' => 0]);
        $this->actingAs($this->makeUser('admin'));
        $before = collect($this->getJson('/api/restock/recommendations')->assertOk()->json('data.recommendations'))
            ->firstWhere('product_id', $product->product_id);
        $this->assertSame(20, $before['on_order_quantity']);
        $line->update(['short_closed_quantity' => 10]);
        $after = collect($this->getJson('/api/restock/recommendations')->assertOk()->json('data.recommendations'))
            ->firstWhere('product_id', $product->product_id);
        $this->assertSame(10, $after['on_order_quantity']);
        $this->assertSame((int) $before['recommended_quantity'] + 10, (int) $after['recommended_quantity']);
    }

    public function test_incoming_refresh_version_rolls_back_with_quantity_updates(): void
    {
        [$po, $line] = $this->setupOrder();
        $before = DB::table('system_settings')->where('key', 'po_incoming_changed')->value('value');
        DB::beginTransaction();
        $line->update(['short_closed_quantity' => 10]);
        DB::rollBack();
        $this->assertSame($before, DB::table('system_settings')->where('key', 'po_incoming_changed')->value('value'));
        $this->assertSame(0, (int) $line->fresh()->short_closed_quantity);
    }

    public function test_legacy_barcode_correction_cannot_rewrite_a_po_receipt(): void
    {
        [$po, $line] = $this->setupOrder();
        $this->actingAs($this->makeUser('admin'));
        $this->receiveCount($line, 7)->assertJsonPath('data.action', 'applied');
        $receipt = DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->first();
        $this->postJson('/api/adjustments/submit', [
            'po_id' => $po->po_id, 'receiving_detail_id' => $receipt->receiving_detail_id,
            'corrected_product_id' => $line->product_id, 'correction_type' => 'wrong_quantity',
            'reason' => 'Do not rewrite historical receiving', 'location_id' => $this->locationId,
        ])->assertStatus(422);
        $this->assertSame(7, (int) Inventory::where('product_id', $line->product_id)->value('quantity_on_hand'));
        $this->assertSame(7, (int) DB::table('receiving_details')->where('receiving_detail_id', $receipt->receiving_detail_id)->value('quantity_amount'));
    }
}
