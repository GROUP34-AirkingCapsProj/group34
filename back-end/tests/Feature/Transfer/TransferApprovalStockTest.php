<?php

namespace Tests\Feature\Transfer;

use App\Models\Transfer;
use App\Models\PickReservation;

class TransferApprovalStockTest extends TransferTestCase
{
    private function pending($from, $to, array $lines): Transfer
    {
        $transfer = Transfer::create([
            'intent' => 'pull', 'from_location_id' => $from->location_id,
            'to_location_id' => $to->location_id, 'transfer_number' => 'TR-STOCK-' . uniqid(),
            'transfer_date' => now(), 'status_id' => $this->statusId('Pending'),
        ]);
        foreach ($lines as [$product, $quantity]) {
            $transfer->details()->create(['product_id' => $product->product_id, 'quantity_requested' => $quantity]);
        }
        return $transfer->fresh('details');
    }

    public function test_overstock_approval_is_rejected_without_changing_request(): void
    {
        $from = $this->makeLocation('B');
        $to = $this->makeLocation('A');
        $product = $this->makeAppliance();
        $this->setStock($from->location_id, $product->product_id, 5);
        $transfer = $this->pending($from, $to, [[$product, 10]]);
        $ia = $this->makeUserAt($to, 'inventory_analyst');
        // Omitting lines must not bypass the stock check.
        $this->actingAs($ia, 'sanctum')->postJson("/api/transfers/{$transfer->transfer_id}/verify", [])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame($this->statusId('Pending'), (int) $transfer->fresh()->status_id);
        $this->assertSame(10, (int) $transfer->details->first()->fresh()->quantity_requested);
    }

    public function test_partial_approval_keeps_only_available_products(): void
    {
        $from = $this->makeLocation('B');
        $to = $this->makeLocation('A');
        $product = $this->makeAppliance();
        $missing = $this->makeInstallationMaterial();
        $stock = $this->setStock($from->location_id, $product->product_id, 5);
        $transfer = $this->pending($from, $to, [[$product, 10], [$missing, 2]]);
        $this->actingAs($this->makeUserAt($to, 'inventory_analyst'), 'sanctum')
            ->postJson("/api/transfers/{$transfer->transfer_id}/verify", ['lines' => [
                ['transfer_detail_id' => $transfer->details[0]->transfer_detail_id, 'quantity_approved' => 5],
                ['transfer_detail_id' => $transfer->details[1]->transfer_detail_id, 'quantity_approved' => 0],
            ]])->assertOk();
        $transfer->refresh()->load('details');
        $this->assertSame($this->statusId('Approved'), (int) $transfer->status_id);
        $this->assertCount(1, $transfer->details);
        $this->assertSame(5, (int) $transfer->details[0]->quantity_requested);
        $this->assertSame(5, (int) $stock->fresh()->available_quantity);
    }

    public function test_duplicate_product_lines_cannot_exceed_stock_together(): void
    {
        $from = $this->makeLocation('B');
        $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $product->product_id, 5);
        $transfer = $this->pending($from, $to, [[$product, 3], [$product, 3]]);
        $this->actingAs($this->makeUserAt($to, 'inventory_analyst'), 'sanctum')
            ->postJson("/api/transfers/{$transfer->transfer_id}/verify", [])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_existing_approved_transfer_and_issuance_hold_reduce_availability(): void
    {
        $from = $this->makeLocation('B');
        $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $product->product_id, 10);
        $approved = $this->pending($from, $to, [[$product, 4]]);
        $approved->update(['status_id' => $this->statusId('Approved')]);
        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => 999999,
            'location_id' => $from->location_id, 'product_id' => $product->product_id,
            'quantity' => 2, 'expires_at' => now()->addHour(),
        ]);
        $transfer = $this->pending($from, $to, [[$product, 5]]);
        $this->actingAs($this->makeUserAt($to, 'inventory_analyst'), 'sanctum')
            ->getJson("/api/transfers/{$transfer->transfer_id}/source-availability")
            ->assertOk()->assertJsonPath("data.{$from->location_id}.{$product->product_id}.available", 4);
        $this->postJson("/api/transfers/{$transfer->transfer_id}/verify", [])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_stock_change_since_panel_open_is_rechecked_on_approval(): void
    {
        $from = $this->makeLocation('B');
        $to = $this->makeLocation('A');
        $product = $this->makeAppliance();
        $stock = $this->setStock($from->location_id, $product->product_id, 5);
        $transfer = $this->pending($from, $to, [[$product, 5]]);
        $this->actingAs($this->makeUserAt($to, 'inventory_analyst'), 'sanctum')
            ->getJson("/api/transfers/{$transfer->transfer_id}/source-availability")->assertOk();
        $stock->update(['available_quantity' => 2, 'quantity_on_hand' => 2]);
        $this->postJson("/api/transfers/{$transfer->transfer_id}/verify", [])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }
}
