<?php

namespace Tests\Feature\Transfer;

use App\Models\Transfer;
use App\Models\Inventory;
use App\Services\TransferService;
use App\Services\TransferProgressService;

class ReusableTransferTest extends TransferTestCase
{
    private function request($from, $to, $product, $user, string $intent = 'pull', int $quantity = 10): Transfer
    {
        $transfer = Transfer::create([
            'intent' => $intent, 'from_location_id' => $from->location_id, 'to_location_id' => $to->location_id,
            'requested_by' => $user->user_id, 'transfer_number' => 'TR-REUSE-' . uniqid(),
            'transfer_date' => now(), 'status_id' => $this->statusId($intent === 'demand' ? 'Approved' : 'Pending'),
            'requested_products' => [['product_id' => $product->product_id, 'quantity_requested' => $quantity]],
        ]);
        $transfer->details()->create(['product_id' => $product->product_id, 'quantity_requested' => $quantity,
            'quantity_scanned' => 0, 'quantity_transferred' => 0, 'quantity_received' => 0]);
        return $transfer->fresh('details');
    }

    public function test_cancelled_demand_releases_picks_and_can_be_edited_and_resubmitted(): void
    {
        $from = $this->makeLocation('B'); $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $stock = $this->setStock($from->location_id, $product->product_id, 5);
        $admin = $this->makeUserAt($to, 'admin');
        $transfer = $this->request($from, $to, $product, $admin, 'demand', 5);
        $service = app(TransferService::class);
        $service->countDispatchScan($transfer, $transfer->details[0]->transfer_detail_id, 2, $admin->user_id);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/transfers/{$transfer->transfer_id}/cancel")
            ->assertOk()->assertJsonPath('data.status.status_name', 'Draft');
        $this->assertSame(0, (int) $transfer->details[0]->fresh()->quantity_scanned);
        $this->assertSame(5, (int) $stock->fresh()->available_quantity);
        $this->patchJson("/api/transfers/{$transfer->transfer_id}/request", [
            'resubmit' => true, 'products' => [['product_id' => $product->product_id, 'quantity_requested' => 3]],
        ])->assertOk()->assertJsonPath('data.status.status_name', 'Approved')
            ->assertJsonPath('data.details.0.quantity_requested', 3);
    }

    public function test_rejected_owner_can_edit_and_submit_again_for_review(): void
    {
        $from = $this->makeLocation('B'); $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $owner = $this->makeUserAt($to, 'warehouse_personnel');
        $transfer = $this->request($from, $to, $product, $owner);
        $transfer->update(['status_id' => $this->statusId('Rejected')]);
        $this->actingAs($owner, 'sanctum')->patchJson("/api/transfers/{$transfer->transfer_id}/request", [
            'resubmit' => true, 'notes' => 'Corrected quantity',
            'products' => [['product_id' => $product->product_id, 'quantity_requested' => 4]],
        ])->assertOk()->assertJsonPath('data.status.status_name', 'Pending');
    }

    public function test_partial_request_and_partial_receipts_can_be_completed_without_double_credit(): void
    {
        $from = $this->makeLocation('B'); $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $source = $this->setStock($from->location_id, $product->product_id, 5);
        $this->makeBatch($product, $from, ['quantity_remaining' => 5, 'received_at' => now()->subDay()]);
        $admin = $this->makeUserAt($to, 'admin');
        $transfer = $this->request($from, $to, $product, $admin);
        $this->actingAs($admin, 'sanctum')->postJson("/api/transfers/{$transfer->transfer_id}/verify", ['lines' => [
            ['transfer_detail_id' => $transfer->details[0]->transfer_detail_id, 'quantity_approved' => 5],
        ]])->assertOk();
        $service = app(TransferService::class);
        $transfer->refresh()->load('details');
        $detailId = $transfer->details[0]->transfer_detail_id;
        $service->countDispatchScan($transfer, $detailId, 5, $admin->user_id);
        $transfer = $service->dispatch($transfer, $admin->user_id);
        $service->countReceiveScan($transfer, $detailId, 3, $admin->user_id);
        $transfer = $service->receive($transfer, $admin->user_id);
        $this->assertSame('Partial', $transfer->status->status_name);
        $destination = Inventory::where('location_id', $to->location_id)->where('product_id', $product->product_id)->firstOrFail();
        $this->assertSame(3, (int) $destination->quantity_on_hand);
        $service->receive($transfer, $admin->user_id);
        $this->assertSame(3, (int) $destination->fresh()->quantity_on_hand);
        $service->countReceiveScan($transfer, $detailId, 2, $admin->user_id);
        $transfer = $service->receive($transfer, $admin->user_id);
        $this->assertSame('Partial', $transfer->status->status_name);
        $this->assertSame(5, (int) $destination->fresh()->quantity_on_hand);
        $this->assertSame(5, app(TransferProgressService::class)->summary($transfer)['lines'][0]['remaining']);
        $next = $this->postJson("/api/transfers/{$transfer->transfer_id}/continue")->assertCreated()->json('data');
        $this->postJson("/api/transfers/{$transfer->transfer_id}/continue")->assertUnprocessable();
        $continuation = Transfer::findOrFail($next['transfer_id'])->load('details');
        $this->assertSame(5, (int) $continuation->details[0]->quantity_requested);
        $source->refresh()->setPieces(5, 1);
        $source->save();
        $this->makeBatch($product, $from, ['quantity_remaining' => 5, 'received_at' => now()]);
        $this->postJson("/api/transfers/{$continuation->transfer_id}/verify", [])->assertOk();
        $continuation->refresh();
        $nextDetail = $continuation->details[0]->transfer_detail_id;
        $service->countDispatchScan($continuation, $nextDetail, 5, $admin->user_id);
        $continuation = $service->dispatch($continuation, $admin->user_id);
        $service->countReceiveScan($continuation, $nextDetail, 5, $admin->user_id);
        $service->receive($continuation, $admin->user_id);
        $this->assertSame('Received', $transfer->fresh()->status->status_name);
        $this->assertSame(10, (int) $destination->fresh()->quantity_on_hand);
        $summary = app(TransferProgressService::class)->summary($transfer->fresh());
        $this->assertTrue($summary['complete']);
        $this->assertSame(0, $summary['lines'][0]['remaining']);
        $this->assertSame(10, (int) \App\Models\InventoryLedgerEntry::where('product_id', $product->product_id)
            ->where('movement_type', 'transfer_in')->sum('quantity'));
    }

    public function test_demand_cannot_be_cancelled_after_dispatch(): void
    {
        $from = $this->makeLocation('B'); $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $product->product_id, 5);
        $admin = $this->makeUserAt($to, 'admin');
        $transfer = $this->request($from, $to, $product, $admin, 'demand', 5);
        $service = app(TransferService::class);
        $service->countDispatchScan($transfer, $transfer->details[0]->transfer_detail_id, 5, $admin->user_id);
        $service->dispatch($transfer, $admin->user_id);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/transfers/{$transfer->transfer_id}/cancel")->assertUnprocessable();
    }

    public function test_incremental_receipt_preserves_fifo_batches(): void
    {
        $from = $this->makeLocation('B'); $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $product->product_id, 5);
        $first = $this->makeBatch($product, $from, ['quantity_remaining' => 3, 'received_at' => now()->subDays(2)]);
        $second = $this->makeBatch($product, $from, ['quantity_remaining' => 2, 'received_at' => now()->subDay()]);
        $admin = $this->makeUserAt($to, 'admin');
        $transfer = $this->request($from, $to, $product, $admin, 'demand', 5);
        $service = app(TransferService::class);
        $did = $transfer->details[0]->transfer_detail_id;
        $service->countDispatchScan($transfer, $did, 5, $admin->user_id);
        $transfer = $service->dispatch($transfer, $admin->user_id);
        $service->countReceiveScan($transfer, $did, 2, $admin->user_id);
        $transfer = $service->receive($transfer, $admin->user_id);
        $service->countReceiveScan($transfer, $did, 3, $admin->user_id);
        $transfer = $service->receive($transfer, $admin->user_id);
        $this->assertSame('Received', $transfer->status->status_name);
        $this->assertSame(3, (int) \App\Models\InventoryBatch::where('location_id', $to->location_id)->where('receiving_id', $first->receiving_id)->sum('quantity_remaining'));
        $this->assertSame(2, (int) \App\Models\InventoryBatch::where('location_id', $to->location_id)->where('receiving_id', $second->receiving_id)->sum('quantity_remaining'));
    }

    public function test_serialized_partial_receipt_moves_each_unit_once_and_protects_posted_scans(): void
    {
        $from = $this->makeLocation('B'); $to = $this->makeLocation('A');
        $product = $this->makeAppliance();
        $this->setStock($from->location_id, $product->product_id, 2);
        $serials = $this->makeSerials($from->location_id, $product->product_id, 2);
        $admin = $this->makeUserAt($to, 'admin');
        $transfer = $this->request($from, $to, $product, $admin, 'demand', 2);
        $service = app(TransferService::class);
        $did = $transfer->details[0]->transfer_detail_id;
        foreach ($serials as $serial) $service->recordDispatchScan($transfer, $serial, $did, $admin->user_id);
        $transfer = $service->dispatch($transfer, $admin->user_id);
        $scan = $service->recordReceiveScan($transfer, $serials[0], $did, $admin->user_id);
        $transfer = $service->receive($transfer, $admin->user_id);
        $this->assertSame('Partial', $transfer->status->status_name);
        try {
            $service->removeReceiveScan($transfer, $scan['scan_id']);
            $this->fail('A posted scan must not be removable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Confirmed receipt scans', $e->getMessage());
        }
        $service->recordReceiveScan($transfer, $serials[1], $did, $admin->user_id);
        $transfer = $service->receive($transfer, $admin->user_id);
        $this->assertSame('Received', $transfer->status->status_name);
        $this->assertSame(2, (int) Inventory::where('location_id', $to->location_id)->where('product_id', $product->product_id)->value('quantity_on_hand'));
        $this->assertSame(2, \App\Models\ItemSerial::whereIn('serial_number', $serials)->where('location_id', $to->location_id)->count());
    }
}
